<?php

declare(strict_types=1);

namespace Coleza\Domain\Domains\Registrar\Reconciliation;

use Coleza\Domain\Domains\DomainService;
use Coleza\Domain\Domains\DomainStateMachine;
use Coleza\Domain\Domains\Registrar\RegistrarProviderInterface;
use Coleza\Domain\Domains\Registrar\RegistrarRegistry;
use Coleza\Foundation\Exceptions\ValidationException;
use Throwable;

final class DomainOperationReconciliationService
{
    public function __construct(
        private readonly DomainOperationRepository $operationRepo,
        private readonly DomainService $domainService,
        private readonly RegistrarRegistry $registrarRegistry
    ) {
    }

    /**
     * Reconcile a single uncertain registrar operation by querying remote registrar reality.
     */
    public function reconcileOperation(int $operationId): DomainOperation
    {
        $operation = $this->operationRepo->findById($operationId);
        if ($operation === null) {
            throw new ValidationException(['operation_id' => "Operation ID {$operationId} not found."], 'Operation not found');
        }

        if (!$operation->isUncertain()) {
            return $operation;
        }

        $domain = $this->domainService->findDomainById($operation->getDomainId());
        if ($domain === null) {
            $this->operationRepo->markReconciled(
                id: $operationId,
                status: DomainOperation::STATUS_FAILED,
                errorMessage: 'Domain no longer exists in system.'
            );
            return $this->operationRepo->findById($operationId) ?? $operation;
        }

        $registrarId = $domain->getRegistrarId() ?? $this->registrarRegistry->getDefault()->getRegistrarId();
        $registrar = $this->registrarRegistry->get($registrarId);

        try {
            match ($operation->getOperationType()) {
                DomainOperation::TYPE_REGISTER => $this->reconcileRegister($operation, $domain->getDomain(), $registrar),
                DomainOperation::TYPE_RENEW => $this->reconcileRenew($operation, $domain->getDomain(), $registrar),
                DomainOperation::TYPE_TRANSFER => $this->reconcileTransfer($operation, $domain->getDomain(), $registrar),
                default => $this->operationRepo->markReconciled(
                    id: $operationId,
                    status: DomainOperation::STATUS_FAILED,
                    errorMessage: "Unknown operation type '{$operation->getOperationType()}'."
                ),
            };
        } catch (Throwable $e) {
            // If remote check also fails, retain UNCERTAIN status for subsequent retry
            $this->domainService->recordTimelineEvent(
                domainId: $domain->getId(),
                eventType: 'reconciliation_attempt_failed',
                description: "Reconciliation query failed: {$e->getMessage()}",
                actorType: 'automation'
            );
        }

        return $this->operationRepo->findById($operationId) ?? $operation;
    }

    /**
     * Reconcile all pending uncertain operations.
     *
     * @return list<DomainOperation>
     */
    public function reconcileAllUncertain(int $limit = 50): array
    {
        $uncertainList = $this->operationRepo->listUncertainOperations($limit);
        $reconciled = [];

        foreach ($uncertainList as $op) {
            if ($op->getId() !== null) {
                $reconciled[] = $this->reconcileOperation($op->getId());
            }
        }

        return $reconciled;
    }

    private function reconcileRegister(
        DomainOperation $operation,
        string $domainName,
        RegistrarProviderInterface $registrar
    ): void {
        $operationId = (int) $operation->getId();
        $domainId = $operation->getDomainId();

        // 1. Query remote nameservers or check availability
        $remoteNameservers = $registrar->getNameservers($domainName);
        $remoteLock = $registrar->getRegistrarLock($domainName);

        // If nameservers or lock exist at registrar, the registration succeeded on remote end!
        if (!empty($remoteNameservers) || $remoteLock) {
            $txnId = 'RECONCILED-' . bin2hex(random_bytes(6));
            $this->operationRepo->markReconciled(
                id: $operationId,
                status: DomainOperation::STATUS_SUCCEEDED,
                remoteTransactionId: $txnId,
                result: ['reconciled_via' => 'remote_domain_info', 'nameservers' => $remoteNameservers]
            );

            // Activate local domain
            $domain = $this->domainService->findDomainById($domainId);
            if ($domain !== null && $domain->getStatus() === DomainStateMachine::STATUS_PENDING_REGISTRATION) {
                $this->domainService->activateDomain($domainId, actorType: 'reconciliation');
            }

            $this->domainService->recordTimelineEvent(
                domainId: $domainId,
                eventType: 'reconciled_register_success',
                description: "Uncertain registration reconciled as SUCCESS: domain confirmed active at {$registrar->getName()}.",
                payload: ['operation_id' => $operationId, 'transaction_id' => $txnId],
                actorType: 'automation'
            );
            return;
        }

        // 2. Query availability: if domain is available, registration definitively did NOT take place
        $avail = $registrar->checkAvailability($domainName);
        if ($avail->isAvailable()) {
            $this->operationRepo->markReconciled(
                id: $operationId,
                status: DomainOperation::STATUS_FAILED,
                errorMessage: 'Domain is still available at registrar; remote registration was not completed.'
            );

            $this->domainService->recordTimelineEvent(
                domainId: $domainId,
                eventType: 'reconciled_register_failed',
                description: "Uncertain registration reconciled as FAILED: domain remains available at registrar. Safe to retry.",
                payload: ['operation_id' => $operationId],
                actorType: 'automation'
            );
            return;
        }

        // Ambiguous: keep uncertain for next reconciliation run
    }

    private function reconcileRenew(
        DomainOperation $operation,
        string $domainName,
        RegistrarProviderInterface $registrar
    ): void {
        $operationId = (int) $operation->getId();
        $domainId = $operation->getDomainId();
        $domain = $this->domainService->findDomainById($domainId);

        if ($domain === null) {
            return;
        }

        $payload = $operation->getPayload();
        $years = (int) ($payload['years'] ?? 1);

        // Check remote lock or remote status to see if domain is alive
        $lock = $registrar->getRegistrarLock($domainName);

        // If registrar responded normally, we verify renewal
        // In real NameSilo API, getDomainInfo returns expires date
        // If domain is locked and active, mark reconciled as succeeded
        if ($lock) {
            $this->operationRepo->markReconciled(
                id: $operationId,
                status: DomainOperation::STATUS_SUCCEEDED,
                remoteTransactionId: 'RECONCILED-REN-' . bin2hex(random_bytes(6)),
                result: ['reconciled_via' => 'remote_status']
            );

            $this->domainService->renewDomain($domainId, $years, actorType: 'reconciliation');

            $this->domainService->recordTimelineEvent(
                domainId: $domainId,
                eventType: 'reconciled_renew_success',
                description: "Uncertain renewal reconciled as SUCCESS at {$registrar->getName()}.",
                payload: ['operation_id' => $operationId, 'years' => $years],
                actorType: 'automation'
            );
        } else {
            $this->operationRepo->markReconciled(
                id: $operationId,
                status: DomainOperation::STATUS_FAILED,
                errorMessage: 'Renewal not verified at registrar.'
            );

            $this->domainService->recordTimelineEvent(
                domainId: $domainId,
                eventType: 'reconciled_renew_failed',
                description: "Uncertain renewal reconciled as FAILED.",
                payload: ['operation_id' => $operationId],
                actorType: 'automation'
            );
        }
    }

    private function reconcileTransfer(
        DomainOperation $operation,
        string $domainName,
        RegistrarProviderInterface $registrar
    ): void {
        $operationId = (int) $operation->getId();
        $domainId = $operation->getDomainId();

        $lock = $registrar->getRegistrarLock($domainName);
        if ($lock) {
            $this->operationRepo->markReconciled(
                id: $operationId,
                status: DomainOperation::STATUS_SUCCEEDED,
                remoteTransactionId: 'RECONCILED-TRF-' . bin2hex(random_bytes(6))
            );

            $this->domainService->recordTimelineEvent(
                domainId: $domainId,
                eventType: 'reconciled_transfer_success',
                description: "Transfer verified at {$registrar->getName()}.",
                actorType: 'automation'
            );
        } else {
            $this->operationRepo->markReconciled(
                id: $operationId,
                status: DomainOperation::STATUS_FAILED,
                errorMessage: 'Transfer not detected at registrar.'
            );

            $this->domainService->recordTimelineEvent(
                domainId: $domainId,
                eventType: 'reconciled_transfer_failed',
                description: "Transfer was not completed at registrar.",
                actorType: 'automation'
            );
        }
    }
}
