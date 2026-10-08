<?php

declare(strict_types=1);

namespace Coleza\Domain\Provisioning\Services;

use Coleza\Domain\Providers\Contracts\ProviderCapability;
use Coleza\Domain\Providers\DTO\ProviderOperationResult;
use Coleza\Domain\Provisioning\Entities\ProvisioningOperation;
use Coleza\Domain\Provisioning\Reconciliation\ReconciliationResult;
use Coleza\Domain\Provisioning\Reconciliation\UncertainResponseReconciliationService;
use Coleza\Domain\Provisioning\Retry\ProvisioningRetryPolicy;
use Coleza\Domain\Provisioning\Workflows\HostingProvisioningRequest;
use Coleza\Domain\Provisioning\Workflows\HostingProvisioningWorkflow;
use DateTimeImmutable;
use Throwable;

final class ProvisioningRetryRunner
{
    public function __construct(
        private ProvisioningOperationService $operationService,
        private UncertainResponseReconciliationService $reconciliationService,
        private HostingProvisioningWorkflow $provisioningWorkflow,
        private ProvisioningRetryPolicy $retryPolicy = new ProvisioningRetryPolicy()
    ) {
    }

    /**
     * Process all operations due for automated retry.
     *
     * @return array<array{operation_uuid: string, action: string, outcome: string, details: mixed}>
     */
    public function processDueRetries(?string $referenceTime = null, int $limit = 50): array
    {
        $due = $this->operationService->getDueRetryOperations($referenceTime);
        $results = [];
        $count = 0;

        foreach ($due as $op) {
            if ($count >= $limit) {
                break;
            }
            $count++;

            $outcome = $this->processSingleOperation($op);
            $results[] = $outcome;
        }

        return $results;
    }

    /**
     * @return array{operation_uuid: string, action: string, outcome: string, details: mixed}
     */
    public function processSingleOperation(ProvisioningOperation $op): array
    {
        $opUuid = $op->getOperationUuid();
        $action = $op->getAction();

        // Step 1: Probe remote state to reconcile ghost creations / uncertain responses
        $reconciliation = $this->reconciliationService->reconcile($op);

        if ($reconciliation->isExists()) {
            return [
                'operation_uuid' => $opUuid,
                'action' => $action,
                'outcome' => 'reconciled_exists',
                'details' => $reconciliation->toArray(),
            ];
        }

        if ($reconciliation->isUnreachable()) {
            // Server remains unreachable
            $this->handleUnreachableNode($op, $reconciliation);
            return [
                'operation_uuid' => $opUuid,
                'action' => $action,
                'outcome' => 'reconciled_unreachable',
                'details' => $reconciliation->getMessage(),
            ];
        }

        // Step 2: Remote account confirmed NOT found; safe to execute retry
        return $this->executeRetryAttempt($op);
    }

    private function handleUnreachableNode(ProvisioningOperation $op, ReconciliationResult $reconciliation): void
    {
        $newAttempt = $op->getAttemptCount() + 1;
        $maxAttempts = $op->getMaxAttempts();

        if ($newAttempt >= $maxAttempts) {
            // Max attempts exceeded; permanently fail
            $failResult = ProviderOperationResult::failure(
                operationType: ProviderCapability::CREATE_ACCOUNT,
                message: "Remote server remained unreachable after {$newAttempt} attempts: {$reconciliation->getMessage()}",
                errorCode: 'MAX_RETRIES_EXCEEDED'
            );
            $this->operationService->recordAttempt($op->getOperationUuid(), $failResult);
            return;
        }

        // Schedule next attempt with exponential backoff
        $category = $op->getErrorClassification()?->getCategory();
        $nextAttemptAt = $this->retryPolicy->calculateNextAttemptAt($newAttempt, $category);

        $failResult = ProviderOperationResult::failure(
            operationType: ProviderCapability::CREATE_ACCOUNT,
            message: "Remote server probe unreachable: {$reconciliation->getMessage()}",
            errorCode: 'SERVER_UNREACHABLE'
        );
        $this->operationService->recordAttempt($op->getOperationUuid(), $failResult);
    }

    /**
     * @return array{operation_uuid: string, action: string, outcome: string, details: mixed}
     */
    private function executeRetryAttempt(ProvisioningOperation $op): array
    {
        $payload = $op->getPayload();
        $serviceId = $op->getServiceId();

        $req = new HostingProvisioningRequest(
            serviceId: $serviceId,
            domain: isset($payload['domain']) ? (string)$payload['domain'] : null,
            username: isset($payload['username']) ? (string)$payload['username'] : null,
            packageIdentifier: isset($payload['package']) ? (string)$payload['package'] : null,
            requiredDiskMb: (int)($payload['disk_limit_mb'] ?? 0),
            requiredBandwidthMb: (int)($payload['bandwidth_limit_mb'] ?? 0),
            preferredServerId: $op->getServerId(),
            providerSlug: $op->getProviderSlug(),
            correlationId: $op->getCorrelationId()
        );

        $result = $this->provisioningWorkflow->execute($req);

        if ($result->isSuccess()) {
            return [
                'operation_uuid' => $op->getOperationUuid(),
                'action' => $op->getAction(),
                'outcome' => 'succeeded_on_retry',
                'details' => $result->toArray(),
            ];
        }

        return [
            'operation_uuid' => $op->getOperationUuid(),
            'action' => $op->getAction(),
            'outcome' => 'failed_on_retry',
            'details' => $result->toArray(),
        ];
    }
}
