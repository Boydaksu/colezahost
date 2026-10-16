<?php
declare(strict_types=1);
namespace Coleza\Domain\Provisioning\Reconciliation;
use Coleza\Domain\Commerce\Services\{ServicePlacement, ServiceService, ServiceStateMachine};
use Coleza\Domain\Providers\Contracts\ProviderCapability;
use Coleza\Domain\Providers\Cpanel\CpanelProvider;
use Coleza\Domain\Providers\DTO\ProviderOperationResult;
use Coleza\Domain\Providers\Registry\ProviderRegistry;
use Coleza\Domain\Provisioning\Entities\ProvisioningOperation;
use Coleza\Domain\Provisioning\Services\ProvisioningOperationService;
use Coleza\Domain\Servers\Services\ServerService;

final class UncertainResponseReconciliationService
{
    public function __construct(private ServerService $serverService, private ProviderRegistry $providerRegistry,
        private ServiceService $serviceService, private ProvisioningOperationService $operationService,
        private ?\Coleza\Domain\Provisioning\Workflows\HostingAdmission $admission = null)
    {
        if ($serviceService->getConnection() !== $operationService->getConnection() || $serverService->getConnection() !== $operationService->getConnection() || ($admission!==null&&$admission->connection()!==$operationService->getConnection())) {
            throw new \LogicException('Reconciliation requires one shared connection for local state and audit.');
        }
    }
    public function reconcile(ProvisioningOperation $operation): ReconciliationResult
    {
        if ($this->operationService->getConnection()->inTransaction()) {
            throw new \LogicException('Remote reconciliation probe must run outside the caller transaction.');
        }
        $payload = $operation->getPayload();
        $unknown = fn(string $reason): ReconciliationResult => $this->persistObservation($operation,
            ReconciliationResult::unknown($operation->getOperationUuid(), $operation->getServiceId(), $reason));
        if(isset($payload['_hosting_admission'])){
            try{$this->operationService->commitReconciliation($operation,fn($current)=>$this->approve($current));}
            catch(\Throwable){return $unknown('APPLICATION_ADMISSION_REQUIRED_OR_CHANGED');}
        }
        if ($operation->getAction() !== ProviderCapability::CREATE_ACCOUNT) { return $unknown('UNSUPPORTED_OPERATION_PROBE'); }
        if ($operation->getStatus() === ProvisioningOperation::STATUS_COMPLETED) {
            return ReconciliationResult::unknown($operation->getOperationUuid(), $operation->getServiceId(), 'OPERATION_ALREADY_COMPLETED');
        }
        foreach (['username', 'domain', 'package'] as $key) {
            if (!is_string($payload[$key] ?? null) || trim($payload[$key]) === '') { return $unknown('INCOMPLETE_TARGET_IDENTITY'); }
        }
        $username = $payload['username']; $serverId = $operation->getServerId();
        if ($serverId === null) { return $unknown('MISSING_SERVER_BINDING'); }
        $server = $this->serverService->findServerById($serverId);
        if ($server === null || !$server->isActive()) {
            return $this->persistObservation($operation, ReconciliationResult::unreachable($operation->getOperationUuid(), $operation->getServiceId(), 'TARGET_SERVER_UNAVAILABLE'));
        }
        if ($server->getProviderSlug() !== $operation->getProviderSlug()) { return $unknown('SERVER_PROVIDER_BINDING_MISMATCH'); }
        try { $provider = $this->providerRegistry->get($operation->getProviderSlug()); }
        catch (\Throwable) { return $unknown('PROVIDER_UNAVAILABLE'); }
        if (!$provider instanceof CpanelProvider) { return $unknown('UNSUPPORTED_PROVIDER_PROBE'); }
        try { $summary = $provider->createClient($server->toServerConnectionDto())->getVerifiedAccountSummary($username); }
        catch (\Coleza\Domain\Providers\Exceptions\ProviderException $error) {
            if (in_array($error->getErrorCode(), ['PROVIDER_CONNECTION_FAILED', 'WHM_SERVER_ERROR'], true)) {
                return $this->persistObservation($operation, ReconciliationResult::unreachable($operation->getOperationUuid(), $operation->getServiceId(), 'REMOTE_PROBE_UNREACHABLE'));
            }
            return $unknown('REMOTE_PROBE_UNAUTHORIZED_OR_INVALID');
        } catch (\Throwable) { return $unknown('REMOTE_PROBE_AMBIGUOUS_OR_INVALID'); }
        if ($summary === null) { return $unknown('REMOTE_ABSENCE_NOT_PROVEN'); }
        if (strcasecmp($summary['domain'], $payload['domain']) !== 0 || $summary['plan'] !== $payload['package']
            || !in_array($summary['suspended'], [0, '0'], true) || filter_var($summary['ip'], FILTER_VALIDATE_IP) === false) {
            return $unknown('REMOTE_ACCOUNT_IDENTITY_OR_ACTIVE_STATE_MISMATCH');
        }
        return $this->operationService->commitReconciliation($operation, function (ProvisioningOperation $current) use ($payload, $summary, $server, $username): ReconciliationResult {
            try{$this->approve($current);}catch(\Throwable){
                $result=ReconciliationResult::unknown($current->getOperationUuid(),$current->getServiceId(),'APPLICATION_ADMISSION_REQUIRED_OR_CHANGED');
                $this->operationService->recordReconciliationObservation($current,$result);return $result;
            }
            if(array_key_exists('_hosting_dispatch',$payload)){
                try{
                    $db=$this->operationService->getConnection();$db->lockRow('servers',$server->getId());
                    \Coleza\Domain\Provisioning\Workflows\HostingDispatchBinding::assertCurrent($db,$current,new \Coleza\Domain\Servers\Capacity\Services\CapacityReservationService($db,$this->serverService));
                }catch(\Throwable){
                    $result=ReconciliationResult::unknown($current->getOperationUuid(),$current->getServiceId(),'DURABLE_DISPATCH_BINDING_CHANGED');
                    $this->operationService->recordReconciliationObservation($current,$result);return $result;
                }
            }
            $service = $this->serviceService->findServiceById($current->getServiceId());
            if ($service === null || $service->getStatus() !== ServiceStateMachine::STATUS_PENDING
                || ($service->getDomain() !== null && $service->getDomain() !== '' && strcasecmp($service->getDomain(), $payload['domain']) !== 0)
                || ($service->getUsername() !== null && $service->getUsername() !== '' && strcasecmp($service->getUsername(), $username) !== 0)) {
                $result = ReconciliationResult::unknown($current->getOperationUuid(), $current->getServiceId(), 'LOCAL_SERVICE_STATE_OR_IDENTITY_MISMATCH');
                $this->operationService->recordReconciliationObservation($current, $result);
                return $result;
            }
            $placement = $this->serviceService->getPlacementForService($current->getServiceId());
            if ($placement !== null && $placement->getServerId() !== null && $placement->getServerId() !== $server->getId()) {
                $result = ReconciliationResult::unknown($current->getOperationUuid(), $current->getServiceId(), 'LOCAL_PLACEMENT_BINDING_MISMATCH');
                $this->operationService->recordReconciliationObservation($current, $result);
                return $result;
            }
            $this->serviceService->assignPlacement($current->getServiceId(), ['server_id' => $server->getId(),
                'server_pool_id' => $server->getServerPoolId(), 'location_id' => $server->getLocationId(), 'status' => ServicePlacement::STATUS_PLACED,
                'package_identifier' => $summary['plan'], 'hostname' => $server->getHostname(), 'dedicated_ip' => $summary['ip']]);
            $this->serviceService->activateService($current->getServiceId(), ['domain' => $summary['domain'], 'username' => $username,
                'server_name' => $server->getName(), 'ip_address' => $summary['ip']]);
            $evidence = ['reconciled' => true, 'remote_summary' => $summary, 'evidence_type' => 'ACCOUNT_POSTCONDITION',
                'historical_operation_applied_proven' => false, 'automatic_retry_allowed' => false,
                'server_id' => $server->getId(), 'provider_slug' => $current->getProviderSlug(), 'observed_at' => (new \DateTimeImmutable())->format('c'),
                'input_fingerprint' => hash('sha256', json_encode([$current->getServiceId(), $current->getServerId(), $current->getProviderSlug(),
                    $current->getAction(), $current->getPayload(), $current->getCorrelationId()], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION))];
            if(isset($payload['_hosting_admission'])){$this->admission->record($current->getServiceId(),$current->getAction(),$current->getOperationUuid(),'reconciled');}
            $this->operationService->recordAttempt($current->getOperationUuid(), ProviderOperationResult::success(ProviderCapability::CREATE_ACCOUNT,
                'Matching active remote account observed; local provisioning reconciled.', $username,
                $evidence, $evidence));
            return ReconciliationResult::exists($current->getOperationUuid(), $current->getServiceId(), $summary,
                'Matching active account observed; original command execution is not independently proven.');
        });
    }
    private function approve(ProvisioningOperation $op):void
    {
        $stored=$op->getPayload()['_hosting_admission']??null;
        if($stored===null){return;}
        if($this->admission===null){throw new \RuntimeException('Authenticated admission context is required.');}
        $receipt=$this->admission->approve($op->getServiceId(),$op->getAction());
        if(($stored['version']??null)!==1||($stored['scope_sha256']??null)!==($receipt['scope_sha256']??null)){throw new \RuntimeException('Application admission changed.');}
    }
    private function persistObservation(ProvisioningOperation $operation, ReconciliationResult $result): ReconciliationResult
    {
        return $this->operationService->commitReconciliation($operation, function (ProvisioningOperation $current) use ($result): ReconciliationResult {
            $this->operationService->recordReconciliationObservation($current, $result);
            return $result;
        });
    }
}
