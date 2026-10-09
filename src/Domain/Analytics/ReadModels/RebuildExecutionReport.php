<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\ReadModels;

use JsonSerializable;

/**
 * Value object summarizing a completed read model aggregation rebuild operation.
 */
final class RebuildExecutionReport implements JsonSerializable
{
    public function __construct(
        private readonly string $scope, // 'daily', 'monthly', 'all'
        private readonly int $itemsRebuiltCount,
        private readonly string $startDate,
        private readonly string $endDate,
        private readonly string $currency,
        private readonly float $durationSeconds,
        private readonly string $completedAt,
        private readonly string $rebuildChecksum
    ) {
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function getItemsRebuiltCount(): int
    {
        return $this->itemsRebuiltCount;
    }

    public function getStartDate(): string
    {
        return $this->startDate;
    }

    public function getEndDate(): string
    {
        return $this->endDate;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getDurationSeconds(): float
    {
        return $this->durationSeconds;
    }

    public function getCompletedAt(): string
    {
        return $this->completedAt;
    }

    public function getRebuildChecksum(): string
    {
        return $this->rebuildChecksum;
    }

    public function toArray(): array
    {
        return [
            'scope' => $this->scope,
            'items_rebuilt_count' => $this->itemsRebuiltCount,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'currency' => $this->currency,
            'duration_seconds' => $this->durationSeconds,
            'completed_at' => $this->completedAt,
            'rebuild_checksum' => $this->rebuildChecksum,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
