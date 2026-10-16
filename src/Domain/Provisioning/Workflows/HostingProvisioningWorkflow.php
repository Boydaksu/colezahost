<?php

declare(strict_types=1);

namespace Coleza\Domain\Provisioning\Workflows;
use Coleza\Domain\Provisioning\Entities\ProvisioningOperation;

use Coleza\Domain\Commerce\Services\ServicePlacement;
use Coleza\Domain\Commerce\Services\ServiceService;
use Coleza\Domain\Commerce\Services\ServiceStateMachine;
use Coleza\Domain\Providers\Contracts\ProviderCapability;
use Coleza\Domain\Providers\Cpanel\CpanelProvider;
use Coleza\Domain\Providers\DTO\ProviderOperationResult;
use Coleza\Domain\Providers\Operations\CreateAccountOperation;
use Coleza\Domain\Providers\Registry\ProviderRegistry;
use Coleza\Domain\Provisioning\Classification\ProvisioningErrorClassifier;
use Coleza\Domain\Provisioning\Services\ProvisioningOperationService;
use Coleza\Domain\Servers\Capacity\Services\CapacityReservationService;
use Coleza\Domain\Servers\Placement\PlacementEngine;
use Coleza\Domain\Servers\Placement\PlacementRequest;
use Coleza\Domain\Servers\Services\ServerService;
use RuntimeException;
use Throwable;

final class HostingProvisioningWorkflow
{
    /** @var array<string> */
    private static array $reservedUsernames = [
        'root', 'bin', 'daemon', 'adm', 'lp', 'sync', 'shutdown', 'halt',
        'mail', 'news', 'uucp', 'operator', 'games', 'gopher', 'ftp',
        'nobody', 'cpanel', 'whm', 'admin', 'test', 'guest', 'user', 'system'
    ];

    public function __construct(
        private ServiceService $serviceService,
        private ServerService $serverService,
        private PlacementEngine $placementEngine,
        private CapacityReservationService $reservationService,
        private ProviderRegistry $providerRegistry,
        private ProvisioningOperationService $operationService
    ) {
        $connection=$operationService->getConnection();
        foreach([$serviceService->getConnection(),$serverService->getConnection(),$reservationService->getConnection()] as $candidate){
            if($candidate!==$connection){throw new \LogicException('Hosting workflow requires one shared Connection instance.');}
        }
    }

    /**
     * Execute the full 6-step hosting provisioning workflow:
     * validate -> place -> reserve -> remote -> verify -> activate
     */
    public function execute(HostingProvisioningRequest $request): HostingProvisioningResult
    {
        $db=$this->operationService->getConnection();
        if($db->getPdo()->inTransaction()){throw new \LogicException('Provider calls cannot run inside a caller transaction.');}
        $serviceId = $request->getServiceId();
        /** @var array<string> $completedSteps */
        $completedSteps = [];

        // ====================================================================
        // STEP 1: VALIDATE
        // ====================================================================
        $service = $this->serviceService->findServiceById($serviceId);
        if ($service === null) {
            return HostingProvisioningResult::failure(
                serviceId: $serviceId,
                failedStep: HostingProvisioningStep::VALIDATE,
                errorMessage: "Service {$serviceId} does not exist.",
                errorCode: 'SERVICE_NOT_FOUND',
                completedSteps: $completedSteps
            );
        }

        // Idempotency: if already active, treat as completed
        $held = $this->operationService->findActiveOperationForService($serviceId, ProviderCapability::CREATE_ACCOUNT);
        if ($held !== null) {
            return HostingProvisioningResult::failure(serviceId: $serviceId, failedStep: HostingProvisioningStep::VALIDATE,
                errorMessage: 'Remote outcome requires reconciliation before starting another provisioning workflow.',
                errorCode: 'RECONCILIATION_REQUIRED', completedSteps: $completedSteps);
        }
        if ($service->getStatus() === ServiceStateMachine::STATUS_ACTIVE) {
            return HostingProvisioningResult::success(
                serviceId: $serviceId,
                serverId: $service->getPlacement()?->getServerId() ?? 0,
                remoteIdentifier: (string)$service->getUsername(),
                remoteIp: (string)$service->getIpAddress(),
                completedSteps: HostingProvisioningStep::allSteps(),
                data: ['idempotent' => true, 'already_active' => true]
            );
        }

        if ($service->getStatus() !== ServiceStateMachine::STATUS_PENDING) {
            return HostingProvisioningResult::failure(
                serviceId: $serviceId,
                failedStep: HostingProvisioningStep::VALIDATE,
                errorMessage: "Service status '{$service->getStatus()}' is not eligible for provisioning (must be 'pending').",
                errorCode: 'INVALID_SERVICE_STATUS',
                completedSteps: $completedSteps
            );
        }

        foreach($this->operationService->listOperationsForService($serviceId) as $prior){
            if($prior->getAction()===ProviderCapability::CREATE_ACCOUNT){return HostingProvisioningResult::failure($serviceId,HostingProvisioningStep::VALIDATE,'Previous remote creation requires explicit reconciliation.','RECONCILIATION_REQUIRED');}
        }
        $serviceFingerprint=HostingDispatchBinding::service($db,$serviceId);
        // Domain validation
        $domain = trim($request->getDomain() ?? ($service->getDomain() ?? ''));
        if ($domain === '' || !str_contains($domain, '.') || filter_var($domain,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)===false || ($service->getDomain()!==null&&$service->getDomain()!==''&&strcasecmp($domain,$service->getDomain())!==0)) {
            return HostingProvisioningResult::failure(
                serviceId: $serviceId,
                failedStep: HostingProvisioningStep::VALIDATE,
                errorMessage: "Invalid or missing domain name '{$domain}'.",
                errorCode: 'INVALID_DOMAIN',
                completedSteps: $completedSteps
            );
        }

        // Username validation / generation
        $username = trim($request->getUsername() ?? ($service->getUsername() ?? ''));
        if ($username === '') {
            $username = $this->generateCpanelUsername($domain, $serviceId);
        } else {
            $username = strtolower($username);
        }

        if (!$this->isValidCpanelUsername($username)) {
            return HostingProvisioningResult::failure(
                serviceId: $serviceId,
                failedStep: HostingProvisioningStep::VALIDATE,
                errorMessage: "Username '{$username}' is invalid for cPanel hosting (1-16 alphanumeric characters, must start with letter, cannot be reserved).",
                errorCode: 'INVALID_USERNAME',
                completedSteps: $completedSteps
            );
        }

        if(($service->getUsername()!==null&&$service->getUsername()!==''&&strcasecmp($username,$service->getUsername())!==0)
            ||($service->getPasswordEncrypted()!==null&&$service->getPasswordEncrypted()!=='')){
            return HostingProvisioningResult::failure($serviceId,HostingProvisioningStep::VALIDATE,'Existing credential identity requires explicit review.','RECONCILIATION_REQUIRED');
        }
        // Password resolution
        $password = $request->getPassword();
        if ($password === null || $password === '') {
            $password = $this->generateSecurePassword();
        }

        // Package identifier resolution
        $package = trim($request->getPackageIdentifier() ?? (string)($service->getMetadata()['package_identifier'] ?? ($service->getMetadata()['plan'] ?? '')));
        if ($package === '') {
            return HostingProvisioningResult::failure(
                serviceId: $serviceId,
                failedStep: HostingProvisioningStep::VALIDATE,
                errorMessage: 'Hosting package identifier is required but not provided.',
                errorCode: 'MISSING_PACKAGE_IDENTIFIER',
                completedSteps: $completedSteps
            );
        }

        $completedSteps[] = HostingProvisioningStep::VALIDATE;

        // ====================================================================
        // STEP 2: PLACE
        // ====================================================================
        $selectedServer = null;

        if ($request->getPreferredServerId() !== null) {
            $candidate = $this->serverService->findServerById($request->getPreferredServerId());
            if ($candidate !== null && $candidate->isActive()) {
                $selectedServer = $candidate;
            } else {
                return HostingProvisioningResult::failure(
                    serviceId: $serviceId,
                    failedStep: HostingProvisioningStep::PLACE,
                    errorMessage: "Preferred server {$request->getPreferredServerId()} is not found or inactive.",
                    errorCode: 'PREFERRED_SERVER_UNAVAILABLE',
                    completedSteps: $completedSteps
                );
            }
        } else {
            $placementReq = new PlacementRequest(
                serviceId: $serviceId,
                productId: $service->getProductId(),
                serverPoolId: $request->getServerPoolId(),
                providerSlug: $request->getProviderSlug(),
                locationId: $request->getLocationId(),
                requiredDiskMb: $request->getRequiredDiskMb(),
                requiredBandwidthMb: $request->getRequiredBandwidthMb(),
                requiredDedicatedIp: $request->requiresDedicatedIp()
            );

            $decision = $this->placementEngine->selectServer($placementReq);
            if (!$decision->isSuccessful() || $decision->getSelectedServer() === null) {
                return HostingProvisioningResult::failure(
                    serviceId: $serviceId,
                    failedStep: HostingProvisioningStep::PLACE,
                    errorMessage: $decision->getMessage(),
                    errorCode: 'PLACEMENT_REJECTED',
                    completedSteps: $completedSteps,
                    data: ['rejection_reasons' => $decision->getRejectionReasons()]
                );
            }
            $selectedServer = $decision->getSelectedServer();
        }

        $serverId = $selectedServer->getId() ?? 0;

        if($selectedServer->getProviderSlug()!==$request->getProviderSlug()
            ||($request->getServerPoolId()!==null&&$selectedServer->getServerPoolId()!==$request->getServerPoolId())
            ||($request->getLocationId()!==null&&$selectedServer->getLocationId()!==$request->getLocationId())
            ||($request->requiresDedicatedIp()&&$selectedServer->getAssignedIpPool()===[])
            ||$request->getRequiredDiskMb()<0||$request->getRequiredBandwidthMb()<0){
            return HostingProvisioningResult::failure($serviceId,HostingProvisioningStep::PLACE,'Selected server does not match placement requirements.','PLACEMENT_REJECTED',$completedSteps);
        }
        try{
            $provider=$this->providerRegistry->get($request->getProviderSlug());
            if(!$provider instanceof CpanelProvider){throw new RuntimeException('No authoritative account verifier is registered for this provider.');}
            // Resolve an alias to the actual remote package before freezing the dispatch target.
            $package=$provider->getPackageService()->resolvePackage($provider->createClient($selectedServer->toServerConnectionDto()),$package)->getName();
        }catch(Throwable $error){return HostingProvisioningResult::failure($serviceId,HostingProvisioningStep::PLACE,'Provider/package admission failed.','PROVIDER_ADMISSION_FAILED',$completedSteps);}
        $completedSteps[]=HostingProvisioningStep::PLACE;
        $reservationToken=null;$op=null;
        try{
            [$op,$reservationToken]=$this->operationService->localTransaction(function()use($db,$request,$serviceId,$serverId,$selectedServer,$username,$domain,$package,$password,$serviceFingerprint,$service):array{
                $db->lockRow('services',$serviceId);
                if(serialize($service)!==serialize($this->serviceService->findServiceById($serviceId))||!hash_equals($serviceFingerprint,HostingDispatchBinding::service($db,$serviceId))){throw new RuntimeException('Service changed during placement.');}
                foreach($this->operationService->listOperationsForService($serviceId) as $prior){if($prior->getAction()===ProviderCapability::CREATE_ACCOUNT){throw new RuntimeException('A create dispatch already exists.');}}
                $db->lockRow('servers',$serverId);
                $freshServer=$this->serverService->findServerById($serverId);
                if($freshServer===null||!$freshServer->isActive()||$freshServer->getProviderSlug()!==$request->getProviderSlug()
                    ||serialize($freshServer->toServerConnectionDto())!==serialize($selectedServer->toServerConnectionDto())
                    ||$freshServer->getServerPoolId()!==$selectedServer->getServerPoolId()||$freshServer->getLocationId()!==$selectedServer->getLocationId()) {throw new RuntimeException('Selected server changed during placement.');}
                $reservation=$this->reservationService->reserve($serverId,1,$request->getRequiredDiskMb(),$request->getRequiredBandwidthMb(),900,$serviceId,poolId:$selectedServer->getServerPoolId());
                $token=$reservation->getToken();
                // Commit the allocation before dispatch so a TTL sweeper cannot free an uncertain remote account.
                $this->reservationService->commit($token,$serviceId);
                $this->serviceService->assignPlacement($serviceId,['server_id'=>$serverId,'server_pool_id'=>$selectedServer->getServerPoolId(),'location_id'=>$selectedServer->getLocationId(),
                    'status'=>ServicePlacement::STATUS_RESERVED,'package_identifier'=>$package,'disk_limit_mb'=>$request->getRequiredDiskMb(),'bandwidth_limit_mb'=>$request->getRequiredBandwidthMb()]);
                $payload=['username'=>$username,'domain'=>$domain,'package'=>$package,'password'=>$password,'disk_limit_mb'=>$request->getRequiredDiskMb(),'bandwidth_limit_mb'=>$request->getRequiredBandwidthMb(),
                    '_hosting_dispatch'=>['version'=>1,'service_sha256'=>HostingDispatchBinding::service($db,$serviceId),'server_sha256'=>HostingDispatchBinding::server($db,$serverId),'reservation_token'=>$token]];
                $queued=$this->operationService->queueOperation($serviceId,$request->getProviderSlug(),ProviderCapability::CREATE_ACCOUNT,$payload,$serverId,correlationId:$request->getCorrelationId());
                return [$this->operationService->startDispatch($queued),$token];
            });
        }catch(Throwable $error){return HostingProvisioningResult::failure($serviceId,HostingProvisioningStep::RESERVE,'Local dispatch preparation failed; no create request was sent.','DISPATCH_PREPARATION_FAILED',$completedSteps,serverId:$serverId);}
        $completedSteps[]=HostingProvisioningStep::RESERVE;
        $failure=function(string $step,string $code,?ProviderOperationResult $result=null)use($op,$serviceId,$serverId,$reservationToken,&$completedSteps):HostingProvisioningResult{
            $result??=ProviderOperationResult::failure(ProviderCapability::CREATE_ACCOUNT,'Remote outcome requires reconciliation.',$code);
            $this->operationService->holdDispatch($op,$result);
            return HostingProvisioningResult::failure($serviceId,$step,'Remote outcome requires reconciliation; capacity is retained.',$code,$completedSteps,$serverId,$reservationToken,
                ProvisioningErrorClassifier::classifyResult($result),$op->getOperationUuid(),['reconciliation_required'=>true]);
        };
        try{
            $createOp=new CreateAccountOperation(serviceId:$serviceId,serviceNumber:$service->getServiceNumber(),username:$username,password:$password,domain:$domain,packageIdentifier:$package,
                resourceQuotas:['disk_limit_mb'=>$request->getRequiredDiskMb(),'bandwidth_limit_mb'=>$request->getRequiredBandwidthMb()],server:$selectedServer->toServerConnectionDto(),
                metadata:array_merge($request->getMetadata(),['email'=>$request->getContactEmail(),'dedicated_ip'=>$request->requiresDedicatedIp()?1:0]));
            $remoteResult=$provider->createAccount($createOp);
        }catch(Throwable){$remoteResult=ProviderOperationResult::failure(ProviderCapability::CREATE_ACCOUNT,'Remote response is uncertain.','PROVIDER_EXCEPTION');}
        if(!$remoteResult->isSuccess()){return $failure(HostingProvisioningStep::REMOTE,$remoteResult->getErrorCode()??'REMOTE_CREATION_FAILED',$remoteResult);}
        $completedSteps[]=HostingProvisioningStep::REMOTE;
        try{
            $summary=$provider->createClient($selectedServer->toServerConnectionDto())->getVerifiedAccountSummary($username);
            if($summary===null||strcasecmp($summary['domain'],$domain)!==0||$summary['plan']!==$package||!in_array($summary['suspended'],[0,'0'],true)
                ||filter_var($summary['ip'],FILTER_VALIDATE_IP)===false){throw new RuntimeException('Remote account postcondition does not match the frozen dispatch.');}
        }catch(Throwable){return $failure(HostingProvisioningStep::VERIFY,'VERIFICATION_FAILED');}
        $completedSteps[]=HostingProvisioningStep::VERIFY;
        $remoteIp=$summary['ip'];$nameservers=[];
        foreach(['nameserver','nameserver2'] as $key){$value=$remoteResult->getData()[$key]??null;if(is_string($value)&&$value!==''){$nameservers[]=$value;}}
        try{
            $this->operationService->commitReconciliation($op,function(ProvisioningOperation $current)use($db,$serviceId,$serverId,$selectedServer,$package,$remoteIp,$domain,$username,$remoteResult,$request):void{
                $db->lockRow('servers',$serverId);
                HostingDispatchBinding::assertCurrent($db,$current,$this->reservationService);
                $service=$this->serviceService->findServiceById($serviceId);
                if($service===null||$service->getStatus()!==ServiceStateMachine::STATUS_PENDING){throw new RuntimeException('Service is no longer pending.');}
                $placement=$this->serviceService->getPlacementForService($serviceId);
                if($placement===null||$placement->getServerId()!==$serverId||$placement->getStatus()!==ServicePlacement::STATUS_RESERVED||$placement->getPackageIdentifier()!==$package){throw new RuntimeException('Reserved placement changed.');}
                $this->serviceService->assignPlacement($serviceId,['server_id'=>$serverId,'server_pool_id'=>$selectedServer->getServerPoolId(),'location_id'=>$selectedServer->getLocationId(),
                    'status'=>ServicePlacement::STATUS_PLACED,'package_identifier'=>$package,'dedicated_ip'=>$remoteIp,'hostname'=>$selectedServer->getHostname(),
                    'disk_limit_mb'=>$request->getRequiredDiskMb(),'bandwidth_limit_mb'=>$request->getRequiredBandwidthMb()]);
                $this->serviceService->activateService($serviceId,['domain'=>$domain,'username'=>$username,'server_name'=>$selectedServer->getName(),'ip_address'=>$remoteIp],$service->getLockVersion());
                $this->operationService->recordAttempt($current->getOperationUuid(),$remoteResult);
            });
        }catch(Throwable){return $failure(HostingProvisioningStep::ACTIVATE,'ACTIVATION_FAILED');}
        $completedSteps[]=HostingProvisioningStep::ACTIVATE;
        return HostingProvisioningResult::success($serviceId,$serverId,$username,$remoteIp,$completedSteps,$reservationToken,$nameservers,$op->getOperationUuid(),
            ['username'=>$username,'domain'=>$domain,'package'=>$package,'server_name'=>$selectedServer->getName(),'server_hostname'=>$selectedServer->getHostname()]);
    }

    /**
     * Generate compliant, clean cPanel username:
     * - lowercase alphanumeric
     * - begins with letter
     * - length 8-12 characters
     */
    public function generateCpanelUsername(string $domain, int $serviceId): string
    {
        $domainClean = preg_replace('/[^a-z0-9]/', '', strtolower(explode('.', $domain)[0]));
        if ($domainClean === '' || !ctype_alpha($domainClean[0])) {
            $domainClean = 'usr' . $domainClean;
        }

        $base = substr($domainClean, 0, 8);
        $suffix = (string)($serviceId % 1000);
        $candidate = substr($base, 0, 16 - strlen($suffix)) . $suffix;

        if (in_array($candidate, self::$reservedUsernames, true)) {
            $candidate = 'c' . $candidate;
        }

        return substr($candidate, 0, 16);
    }

    public function isValidCpanelUsername(string $username): bool
    {
        if (strlen($username) < 1 || strlen($username) > 16) {
            return false;
        }

        if (!preg_match('/^[a-z][a-z0-9]{0,15}$/', $username)) {
            return false;
        }

        if (in_array($username, self::$reservedUsernames, true)) {
            return false;
        }

        return true;
    }

    private function generateSecurePassword(): string
    {
        return 'P@ss-' . bin2hex(random_bytes(8));
    }
}
