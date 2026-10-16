<?php
declare(strict_types=1);
namespace Coleza\Domain\Provisioning\Workflows;
use Coleza\Domain\Commerce\Services\{ServiceService,ProviderServiceEntitlement};
use Coleza\Domain\Providers\Cpanel\{CpanelProvider,CpanelApiClient};
use Coleza\Domain\Providers\DTO\ProviderOperationResult;
use Coleza\Domain\Providers\Operations\{SuspendAccountOperation,UnsuspendAccountOperation,TerminateAccountOperation};
use Coleza\Domain\Providers\Registry\ProviderRegistry;
use Coleza\Domain\Provisioning\Entities\ProvisioningOperation;
use Coleza\Domain\Provisioning\Services\ProvisioningOperationService;
use Coleza\Domain\Servers\Services\ServerService;
use Coleza\Domain\Servers\Capacity\Services\CapacityReservationService;
use RuntimeException;
/** Durable claim -> remote command -> independently observed postcondition -> atomic local completion. */
final class HostingLifecycleWorkflow
{
    public function __construct(private ServiceService $services,private ServerService $servers,private CapacityReservationService $capacity,
        private ProvisioningOperationService $operations,private ProviderRegistry $providers)
    {
        $db=$operations->getConnection();foreach([$services->getConnection(),$servers->getConnection(),$capacity->getConnection()]as$c){if($c!==$db){throw new \LogicException('Lifecycle requires one shared connection.');}}
    }
    private function outside(HostingAdmission $admission):void
    {
        if($admission->connection()!==$this->operations->getConnection()||$admission->connection()->getPdo()->inTransaction()){throw new \LogicException('Lifecycle requires its own local transactions and shared admission connection.');}
    }
    private function target(int $id):array
    {
        $db=$this->operations->getConnection();$service=$this->services->findServiceById($id)??throw new RuntimeException('Service missing.');
        $placement=$db->selectOne('SELECT * FROM service_placements WHERE service_id=? ORDER BY id DESC LIMIT 1'.$db->forUpdate(),[$id]);
        if($placement===null||$placement['status']!=='placed'||$placement['released_at']!==null){throw new RuntimeException('Verified placed hosting target required.');}
        $server=$this->servers->findServerById((int)$placement['server_id']);$reservation=$this->capacity->findActiveReservationForService($id);
        if($server===null||!$server->isActive()||$server->getProviderSlug()!=='cpanel'||$reservation===null||!$reservation->isCommitted()
            ||$reservation->getServerId()!==$server->getId()||$service->getUsername()===null||$service->getDomain()===null||$service->getIpAddress()===null){throw new RuntimeException('Bound current hosting identity and allocation required.');}
        $provider=$this->providers->get('cpanel');if(!$provider instanceof CpanelProvider){throw new RuntimeException('Authoritative cPanel verifier required.');}
        $payload=['username'=>$service->getUsername(),'domain'=>$service->getDomain(),'package'=>$placement['package_identifier'],'ip'=>$service->getIpAddress(),
            'reservation_token'=>$reservation->getToken(),'service_sha256'=>HostingDispatchBinding::service($db,$id),'server_sha256'=>HostingDispatchBinding::server($db,$server->getId()),
            'placement_sha256'=>ProviderServiceEntitlement::fingerprint($placement),'capacity_sha256'=>ProviderServiceEntitlement::fingerprint($db->selectOne('SELECT * FROM capacity_reservations WHERE reservation_token=?'.$db->forUpdate(),[$reservation->getToken()]))];
        return [$service,$server,$provider,$payload];
    }
    private function verify(CpanelApiClient $client,array $payload,string $action,bool $before=false):void
    {
        if($action==='terminate_account'&&!$before){if(!$client->verifiedAccountAbsent($payload['username'])){throw new RuntimeException('Remote deletion is not proven.');}return;}
        $summary=$client->getVerifiedAccountSummary($payload['username']);
        if($summary===null||strcasecmp($summary['domain'],$payload['domain'])!==0||$summary['plan']!==$payload['package']||$summary['ip']!==$payload['ip']){throw new RuntimeException('Remote hosting identity mismatch.');}
        if(!$before&&(int)$summary['suspended']!==($action==='suspend_account'?1:0)){throw new RuntimeException('Remote lifecycle postcondition not observed.');}
        if($before&&$action==='terminate_account'&&$client->verifiedAccountAbsent($payload['username'])){throw new RuntimeException('Target must be visible in the root account listing.');}
    }
    private function check(ProvisioningOperation $op,HostingAdmission $admission):void
    {
        \Coleza\Domain\Provisioning\Services\HostingExecutionLease::assertHeld($this->operations->getConnection(),$op->getServiceId());
        [$service,$server,$provider,$current]=$this->target($op->getServiceId());$payload=$op->getPayload();
        if($current!==($payload['_hosting_lifecycle']??null)||$server->getId()!==$op->getServerId()){throw new RuntimeException('Durable lifecycle binding changed.');}
        $fresh=$admission->approve($op->getServiceId(),$op->getAction());$stored=$payload['_hosting_admission']??[];
        if(($fresh['scope_sha256']??null)!==($stored['scope_sha256']??null)){throw new RuntimeException('Lifecycle commercial admission changed.');}
    }
    public function execute(int $id,string $action,HostingAdmission $admission,string $reason=''):ProvisioningOperation
    {
        $this->outside($admission);$lease=\Coleza\Domain\Provisioning\Services\HostingExecutionLease::acquire($this->operations->getConnection(),$id);
        try{return $this->executeOwned($id,$action,$admission,$reason);}finally{$lease->close();}
    }
    private function executeOwned(int $id,string $action,HostingAdmission $admission,string $reason):ProvisioningOperation
    {
        $this->outside($admission);
        if(!in_array($action,['suspend_account','unsuspend_account','terminate_account'],true)||trim($reason)===''||strlen($reason)>1000){throw new RuntimeException('Supported lifecycle action and bounded reason required.');}
        [$receipt,$service,$server,$provider,$payload]=$this->operations->localTransaction(function()use($id,$action,$admission):array{
            $receipt=$admission->approve($id,$action);[$service,$server,$provider,$payload]=$this->target($id);
            if($this->operations->findActiveOperationForService($id)!==null){throw new RuntimeException('Reconcile the existing provider dispatch first.');}
            $eligible=match($action){'suspend_account'=>['active'],'unsuspend_account'=>['suspended'],'terminate_account'=>['active','suspended']};
            if(!in_array($service->getStatus(),$eligible,true)){throw new RuntimeException('Service state does not allow this lifecycle command.');}
            return [$receipt,$service,$server,$provider,$payload];
        });
        $client=$provider->createClient($server->toServerConnectionDto());$this->verify($client,$payload,$action,true);
        $op=$this->operations->localTransaction(function()use($id,$action,$admission,$reason,$receipt,$server,$payload):ProvisioningOperation{
            $fresh=$admission->approve($id,$action);[,,$provider,$target]=$this->target($id);
            if($fresh!==$receipt||$target!==$payload||$this->operations->findActiveOperationForService($id)!==null){throw new RuntimeException('Lifecycle target/admission changed during preflight.');}
            $queued=$this->operations->queueOperation($id,'cpanel',$action,['_hosting_lifecycle'=>$payload,'_hosting_admission'=>$receipt,'reason'=>$reason],$server->getId());
            $admission->record($id,$action,$queued->getOperationUuid(),'prepared');return $this->operations->startDispatch($queued);
        });
        try{
            $this->operations->commitReconciliation($op,fn($current)=>$this->check($current,$admission));
            $result=match($action){
                'suspend_account'=>$provider->suspendAccount(new SuspendAccountOperation($id,$payload['username'],$reason,$server->toServerConnectionDto())),
                'unsuspend_account'=>$provider->unsuspendAccount(new UnsuspendAccountOperation($id,$payload['username'],$server->toServerConnectionDto())),
                'terminate_account'=>$provider->terminateAccount(new TerminateAccountOperation($id,$payload['username'],false,$server->toServerConnectionDto()))};
            if(!$result->isSuccess()){throw new RuntimeException('Remote command did not return success.');}
            $this->verify($client,$payload,$action);$this->complete($op,$admission,false);
        }catch(\Throwable){$this->operations->holdDispatch($op,ProviderOperationResult::failure($action,'Remote outcome requires explicit reconciliation.','LIFECYCLE_RECONCILIATION_REQUIRED'));}
        return $this->operations->findOperationByUuid($op->getOperationUuid());
    }
    /** Recovery observes only; never repeats a provider mutation. Different currently authorized staff may recover. */
    public function reconcile(ProvisioningOperation $op,HostingAdmission $admission):ProvisioningOperation
    {
        $this->outside($admission);$lease=\Coleza\Domain\Provisioning\Services\HostingExecutionLease::acquire($this->operations->getConnection(),$op->getServiceId());
        try{return $this->reconcileOwned($op,$admission);}finally{$lease->close();}
    }
    private function reconcileOwned(ProvisioningOperation $op,HostingAdmission $admission):ProvisioningOperation
    {
        $this->outside($admission);
        if(!in_array($op->getStatus(),['processing','reconciliation_required'],true)||!isset($op->getPayload()['_hosting_lifecycle'])){throw new RuntimeException('Pending bound lifecycle dispatch required.');}
        $this->operations->commitReconciliation($op,fn($current)=>$this->check($current,$admission));
        [,$server,$provider]=$this->target($op->getServiceId());$this->verify($provider->createClient($server->toServerConnectionDto()),$op->getPayload()['_hosting_lifecycle'],$op->getAction());
        $this->complete($op,$admission,true);return $this->operations->findOperationByUuid($op->getOperationUuid());
    }
    private function complete(ProvisioningOperation $op,HostingAdmission $admission,bool $reconciled):void
    {
        $this->operations->commitReconciliation($op,function($current)use($admission,$reconciled):void{
            $this->check($current,$admission);$id=$current->getServiceId();$service=$this->services->findServiceById($id);$payload=$current->getPayload();$action=$current->getAction();
            match($action){'suspend_account'=>$this->services->suspendService($id,$payload['reason'],$service->getLockVersion()),
                'unsuspend_account'=>$this->services->unsuspendService($id,$service->getLockVersion()),
                'terminate_account'=>$this->services->terminateService($id,$payload['reason'],$service->getLockVersion())};
            if($action==='terminate_account'){$this->services->releasePlacement($id);$this->capacity->release($payload['_hosting_lifecycle']['reservation_token'],'verified_remote_termination');}
            $admission->record($id,$action,$current->getOperationUuid(),$reconciled?'reconciled':'completed');
            $this->operations->recordAttempt($current->getOperationUuid(),ProviderOperationResult::success($action,'Remote postcondition observed and local lifecycle committed.',$payload['_hosting_lifecycle']['username'],['reconciled'=>$reconciled,'historical_operation_applied_proven'=>false]));
        });
    }
}
