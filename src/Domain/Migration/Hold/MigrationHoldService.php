<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Hold;

/**
 * Service to manage migration holds, protecting imported data from inadvertent
 * cron triggers, termination shocks, and automated communications.
 */
final class MigrationHoldService
{
    public function __construct(
        private MigrationHoldRepository $repository
    ) {
    }

    /**
     * Places a specific entity on migration hold.
     *
     * @param list<string> $suppressedActions
     */
    public function holdEntity(
        string $batchId,
        string $entityType,
        int|string $entityId,
        string $reason = 'WHMCS migration safety hold',
        array $suppressedActions = ['*']
    ): MigrationHoldRecord {
        $record = new MigrationHoldRecord(
            batchId: $batchId,
            entityType: strtolower(trim($entityType)),
            entityId: (string) $entityId,
            reason: $reason,
            status: MigrationHoldStatus::ACTIVE,
            suppressedActions: $suppressedActions
        );

        return $this->repository->save($record);
    }

    /**
     * Places multiple entities of a given type on migration hold.
     *
     * @param list<int|string> $entityIds
     * @param list<string> $suppressedActions
     */
    public function holdEntities(
        string $batchId,
        string $entityType,
        array $entityIds,
        string $reason = 'WHMCS migration safety hold',
        array $suppressedActions = ['*']
    ): int {
        $count = 0;
        foreach ($entityIds as $id) {
            $this->holdEntity($batchId, $entityType, $id, $reason, $suppressedActions);
            $count++;
        }
        return $count;
    }

    /**
     * Activates a platform-wide global migration hold for a batch.
     *
     * @param list<string> $suppressedActions
     */
    public function activateGlobalHold(
        string $batchId,
        string $reason = 'Global migration safety hold active',
        array $suppressedActions = ['*']
    ): MigrationHoldRecord {
        $record = new MigrationHoldRecord(
            batchId: $batchId,
            entityType: 'GLOBAL',
            entityId: '*',
            reason: $reason,
            status: MigrationHoldStatus::ACTIVE,
            suppressedActions: $suppressedActions
        );

        return $this->repository->save($record);
    }

    public function releaseGlobalHold(string $batchId, string $releasedBy = 'admin', ?string $notes = null): bool
    {
        return $this->repository->releaseGlobalHold($batchId, $releasedBy, $notes);
    }

    public function releaseEntity(string $entityType, int|string $entityId, string $releasedBy = 'admin', ?string $notes = null): bool
    {
        return $this->repository->releaseEntity($entityType, $entityId, $releasedBy, $notes);
    }

    public function releaseBatch(string $batchId, string $releasedBy = 'admin', ?string $notes = null): int
    {
        return $this->repository->releaseBatch($batchId, $releasedBy, $notes);
    }

    public function isGlobalHoldActive(?string $batchId = null): bool
    {
        return $this->repository->findGlobalHold($batchId) !== null;
    }

    public function isEntityHeld(string $entityType, int|string $entityId): bool
    {
        return $this->repository->findActive($entityType, $entityId) !== null;
    }

    /**
     * Checks whether an automated action (e.g. 'suspend', 'terminate', 'renew', 'invoice')
     * must be suppressed for the given entity.
     */
    public function isAutomationSuppressed(string $entityType, int|string $entityId, ?string $action = null): bool
    {
        // 1. If global hold is active, everything is suppressed
        $globalHold = $this->repository->findGlobalHold();
        if ($globalHold !== null && $globalHold->isActionSuppressed($action)) {
            return true;
        }

        // 2. Check entity-level hold
        $entityHold = $this->repository->findActive($entityType, $entityId);
        if ($entityHold !== null && $entityHold->isActionSuppressed($action)) {
            return true;
        }

        return false;
    }

    /**
     * @return array{total: int, active: int, released: int, by_type: array<string, array{active: int, released: int}>}
     */
    public function getHeldEntitiesSummary(string $batchId): array
    {
        return $this->repository->getSummary($batchId);
    }

    /**
     * @return list<MigrationHoldRecord>
     */
    public function getHeldRecords(string $batchId, ?MigrationHoldStatus $status = null): array
    {
        return $this->repository->findByBatch($batchId, $status);
    }

    public function getRepository(): MigrationHoldRepository
    {
        return $this->repository;
    }
}
