<?php

declare(strict_types=1);

namespace Coleza\Domain\Provisioning\Reconciliation;

use Coleza\Domain\Commerce\Services\ServicePlacement;
use Coleza\Domain\Commerce\Services\ServiceService;
use Coleza\Domain\Commerce\Services\ServiceStateMachine;
use Coleza\Domain\Providers\Contracts\ProviderCapability;
use Coleza\Domain\Providers\Cpanel\CpanelProvider;
use Coleza\Domain\Providers\DTO\ProviderOperationResult;
use Coleza\Domain\Providers\Registry\ProviderRegistry;
use Coleza\Domain\Provisioning\Entities\ProvisioningOperation;
use Coleza\Domain\Provisioning\Services\ProvisioningOperationService;
use Coleza\Domain\Servers\Services\ServerService;
use Throwable;

final class UncertainResponseReconciliationService
{
    public function __construct(
        private ServerService $serverService,
        private ProviderRegistry $providerRegistry,
        private ServiceService $serviceService,
        private ProvisioningOperationService $operationService
    ) {
    }

    /**
     * Probes remote provider state to reconcile an uncertain, timed-out, or retrying operation.
     */
    public function reconcile(ProvisioningOperation $operation): ReconciliationResult
    {
        $payload = $operation->getPayload();
        $username = isset($payload['username']) ? (string)$payload['username'] : null;
        $opUuid = $operation->getOperationUuid();
        $serviceId = $operation->getServiceId();

        if ($username === null || $username === '') {
            return ReconciliationResult::notFound(
                operationUuid: $opUuid,
                serviceId: $serviceId,
                message: 'Operation payload does not contain a username for remote probe.'
            );
        }

        $serverId = $operation->getServerId();
        if ($serverId === null) {
            return ReconciliationResult::notFound(
                operationUuid: $opUuid,
                serviceId: $serviceId,
                message: 'No server bound to operation for probe.'
            );
        }

        $server = $this->serverService->findServerById($serverId);
        if ($server === null || !$server->isActive()) {
            return ReconciliationResult::unreachable(
                operationUuid: $opUuid,
                serviceId: $serviceId,
                message: "Target server {$serverId} is unavailable for reconciliation probe."
            );
        }

        try {
            $provider = $this->providerRegistry->get($operation->getProviderSlug());
        } catch (Throwable $e) {
            return ReconciliationResult::unreachable(
                operationUuid: $opUuid,
                serviceId: $serviceId,
                message: "Provider '{$operation->getProviderSlug()}' unavailable: {$e->getMessage()}"
            );
        }

        if ($provider instanceof CpanelProvider) {
            try {
                $client = $provider->createClient($server->toServerConnectionDto());
                $summary = $client->getAccountSummary($username);
            } catch (Throwable $e) {
                // Remote node still unreachable / network failure
                return ReconciliationResult::unreachable(
                    operationUuid: $opUuid,
                    serviceId: $serviceId,
                    message: "Remote WHM probe failed: {$e->getMessage()}"
                );
            }

            // Case A: Account was created on remote host despite ambiguous response / timeout
            if ($summary !== null && strcasecmp((string)($summary['user'] ?? ''), $username) === 0) {
                $remoteIp = (string)($summary['ip'] ?? $server->getIpAddress());
                $domain = (string)($summary['domain'] ?? ($payload['domain'] ?? ''));

                // Reconcile local service state
                $service = $this->serviceService->findServiceById($serviceId);
                if ($service !== null && $service->getStatus() === ServiceStateMachine::STATUS_PENDING) {
                    $this->serviceService->assignPlacement($serviceId, [
                        'server_id' => $server->getId(),
                        'server_pool_id' => $server->getServerPoolId(),
                        'location_id' => $server->getLocationId(),
                        'status' => ServicePlacement::STATUS_PLACED,
                        'package_identifier' => (string)($summary['plan'] ?? ($payload['package'] ?? '')),
                        'hostname' => $server->getHostname(),
                        'dedicated_ip' => $remoteIp,
                    ]);

                    $this->serviceService->activateService($serviceId, [
                        'domain' => $domain,
                        'username' => $username,
                        'server_name' => $server->getName(),
                        'ip_address' => $remoteIp,
                    ]);
                }

                // Transition operation to completed
                $successRes = ProviderOperationResult::success(
                    operationType: ProviderCapability::CREATE_ACCOUNT,
                    message: "Reconciled ghost/uncertain response: cPanel account '{$username}' confirmed active on remote node.",
                    externalIdentifier: $username,
                    data: [
                        'reconciled' => true,
                        'remote_summary' => $summary,
                    ]
                );
                $this->operationService->recordAttempt($opUuid, $successRes);

                return ReconciliationResult::exists(
                    operationUuid: $opUuid,
                    serviceId: $serviceId,
                    remoteData: $summary,
                    message: "Account '{$username}' exists on remote node; reconciled as completed."
                );
            }

            // Case B: Account definitely does not exist on remote host; safe to retry
            return ReconciliationResult::notFound(
                operationUuid: $opUuid,
                serviceId: $serviceId,
                message: "Account '{$username}' does not exist on remote node; safe for retry execution."
            );
        }

        return ReconciliationResult::notFound(
            operationUuid: $opUuid,
            serviceId: $serviceId,
            message: "Provider '{$operation->getProviderSlug()}' does not support remote summary probe."
        );
    }
}
