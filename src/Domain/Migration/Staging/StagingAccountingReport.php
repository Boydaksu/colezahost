<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Staging;

use JsonSerializable;

/**
 * Terminal accounting report verifying the Zero Silent Data Loss invariant.
 * Every source record entered into staging must resolve to a known state with zero unexplained diff.
 */
final class StagingAccountingReport implements JsonSerializable
{
    /**
     * @param array<string, int> $statusCounts
     */
    public function __construct(
        private string $batchId,
        private int $totalStagedRecords,
        private int $migratedCount,
        private int $quarantinedCount,
        private int $skippedCount,
        private int $failedCount,
        private int $inFlightCount,
        private array $statusCounts = []
    ) {
    }

    public function getBatchId(): string
    {
        return $this->batchId;
    }

    public function getTotalStagedRecords(): int
    {
        return $this->totalStagedRecords;
    }

    public function getTotalStaged(): int
    {
        return $this->totalStagedRecords;
    }

    public function getTerminalCount(): int
    {
        return $this->migratedCount + $this->quarantinedCount + $this->skippedCount + $this->failedCount;
    }

    public function getMigratedCount(): int
    {
        return $this->migratedCount;
    }

    public function getQuarantinedCount(): int
    {
        return $this->quarantinedCount;
    }

    public function getSkippedCount(): int
    {
        return $this->skippedCount;
    }

    public function getFailedCount(): int
    {
        return $this->failedCount;
    }

    public function getInFlightCount(): int
    {
        return $this->inFlightCount;
    }

    /**
     * @return array<string, int>
     */
    public function getStatusCounts(): array
    {
        return $this->statusCounts;
    }

    /**
     * Zero Silent Loss Invariant:
     * Unaccounted diff must be exactly 0.
     */
    public function getUnaccountedDiff(): int
    {
        $sum = $this->migratedCount + $this->quarantinedCount + $this->skippedCount + $this->failedCount + $this->inFlightCount;
        return $this->totalStagedRecords - $sum;
    }

    public function isZeroSilentLossAchieved(): bool
    {
        return $this->getUnaccountedDiff() === 0;
    }

    public function isFullyTerminal(): bool
    {
        return $this->inFlightCount === 0 && $this->isZeroSilentLossAchieved();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'batch_id' => $this->batchId,
            'total_staged_records' => $this->totalStagedRecords,
            'migrated_count' => $this->migratedCount,
            'quarantined_count' => $this->quarantinedCount,
            'skipped_count' => $this->skippedCount,
            'failed_count' => $this->failedCount,
            'in_flight_count' => $this->inFlightCount,
            'unaccounted_diff' => $this->getUnaccountedDiff(),
            'zero_silent_loss' => $this->isZeroSilentLossAchieved(),
            'is_fully_terminal' => $this->isFullyTerminal(),
            'status_counts' => $this->statusCounts,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
