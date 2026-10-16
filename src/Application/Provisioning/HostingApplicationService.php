<?php
declare(strict_types=1);
namespace Coleza\Application\Provisioning;
use Coleza\Domain\Provisioning\Workflows\{HostingProvisioningWorkflow,HostingLifecycleWorkflow,HostingProvisioningResult};
use Coleza\Domain\Provisioning\Services\ProvisioningOperationService;
use Coleza\Domain\Provisioning\Entities\ProvisioningOperation;
use Coleza\Domain\Provisioning\Reconciliation\{UncertainResponseReconciliationService,ReconciliationResult};
use Coleza\Domain\Commerce\Services\ServiceService;
use Coleza\Domain\Servers\Services\ServerService;
use Coleza\Domain\Providers\Registry\ProviderRegistry;
use Coleza\Application\Identity\CommandAuthorizer;
/** Explicit Application boundary. HTTP/CLI/worker authenticated composition is a separate delivery. */
final class HostingApplicationService
{
    public function __construct(private HostingProvisioningWorkflow $create,private HostingLifecycleWorkflow $lifecycle,
        private ProvisioningOperationService $operations,private ServiceService $services,private ServerService $servers,private ProviderRegistry $providers)
    {
        foreach([$services->getConnection(),$servers->getConnection()]as$db){if($db!==$operations->getConnection()){throw new \LogicException('Hosting application requires one shared connection.');}}
    }
    public function provision(int $serviceId,int $actorId):HostingProvisioningResult
    {
        $db=$this->operations->getConnection();if($db->getPdo()->inTransaction()){throw new \LogicException('Caller transaction is not allowed.');}
        $policy=new HostingCommandAdmission($db,$actorId);
        $request=$this->operations->localTransaction(function()use($serviceId,$actorId,$policy,$db){(new CommandAuthorizer($db))->requireSystem($actorId,'services.provision');return $policy->request($serviceId);});
        return $this->create->execute($request,$policy);
    }
    public function suspend(int $id,int $actor,string $reason):ProvisioningOperation{return $this->lifecycle->execute($id,'suspend_account',new HostingCommandAdmission($this->operations->getConnection(),$actor),$reason);}
    public function unsuspend(int $id,int $actor,string $reason):ProvisioningOperation{return $this->lifecycle->execute($id,'unsuspend_account',new HostingCommandAdmission($this->operations->getConnection(),$actor),$reason);}
    public function terminate(int $id,int $actor,string $reason):ProvisioningOperation{return $this->lifecycle->execute($id,'terminate_account',new HostingCommandAdmission($this->operations->getConnection(),$actor),$reason);}
    public function reconcile(string $uuid,int $actor):ProvisioningOperation|ReconciliationResult
    {
        $policy=new HostingCommandAdmission($this->operations->getConnection(),$actor);$op=$this->operations->findOperationByUuid($uuid)??throw new \RuntimeException('Provider operation not found.');
        if(isset($op->getPayload()['_hosting_lifecycle'])){return $this->lifecycle->reconcile($op,$policy);}
        if(!isset($op->getPayload()['_hosting_admission'])){throw new \RuntimeException('Application admission receipt required.');}
        return (new UncertainResponseReconciliationService($this->servers,$this->providers,$this->services,$this->operations,$policy))->reconcile($op);
    }
}
