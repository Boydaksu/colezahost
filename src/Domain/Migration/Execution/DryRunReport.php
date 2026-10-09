<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Execution;

use JsonSerializable;

/**
 * Encapsulates the results of a migration dry-run simulation.
 * Verifies that all records can be parsed, mapped, validated, and reconciled
 * without committing any mutations to production tables.
 */
final class DryRunReport implements JsonSerializable
{
    /**
     * @param array<string, int> $inspectedByType
     * @param array<string, int> $projectedMigratedByType
     * @param array<string, int> $projectedQuarantinedByType
     * @param array<string, list<array<string, mixed>>> $conflictsDetected
     * @param array<string, array<string, float>> $projectedFinancialTotals
     */
    public function __construct(
        private string $batchId,
        private int $totalInspected,
        private int $totalProjectedMigrated,
        private int $totalProjectedQuarantined,
        private array $inspectedByType,
        private array $projectedMigratedByType,
        private array $projectedQuarantinedByType,
        private array $conflictsDetected,
        private array $projectedFinancialTotals
    ) {
    }

    public function getBatchId(): string
    {
        return $this->batchId;
    }

    public function isDryRun(): bool
    {
        return true;
    }

    public function getTotalInspected(): int
    {
        return $this->totalInspected;
    }

    public function getTotalProjectedMigrated(): int
    {
        return $this->totalProjectedMigrated;
    }

    public function getTotalProjectedQuarantined(): int
    {
        return $this->totalProjectedQuarantined;
    }

    /**
     * @return array<string, int>
     */
    public function getInspectedByType(): array
    {
        return $this->inspectedByType;
    }

    /**
     * @return array<string, int>
     */
    public function getProjectedMigratedByType(): array
    {
        return $this->projectedMigratedByType;
    }

    /**
     * @return array<string, int>
     */
    public function getProjectedQuarantinedByType(): array
    {
        return $this->projectedQuarantinedByType;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function getConflictsDetected(): array
    {
        return $this->conflictsDetected;
    }

    /**
     * @return array<string, array<string, float>>
     */
    public function getProjectedFinancialTotals(): array
    {
        return $this->projectedFinancialTotals;
    }

    public function hasConflicts(): bool
    {
        foreach ($this->conflictsDetected as $conflicts) {
            if (!empty($conflicts)) {
                return true;
            }
        }
        return false;
    }

    public function canProceedSafely(): bool
    {
        return !$this->hasConflicts() && $this->totalProjectedMigrated > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'batch_id' => $this->batchId,
            'dry_run' => true,
            'can_proceed_safely' => $this->canProceedSafely(),
            'has_conflicts' => $this->hasConflicts(),
            'total_inspected' => $this->totalInspected,
            'total_projected_migrated' => $this->totalProjectedMigrated,
            'total_projected_quarantined' => $this->totalProjectedQuarantined,
            'inspected_by_type' => $this->inspectedByType,
            'projected_migrated_by_type' => $this->projectedMigratedByType,
            'projected_quarantined_by_type' => $this->projectedQuarantinedByType,
            'conflicts_detected' => $this->conflictsDetected,
            'projected_financial_totals' => $this->projectedFinancialTotals,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
