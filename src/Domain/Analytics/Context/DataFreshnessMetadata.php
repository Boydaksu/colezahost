<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Context;

use JsonSerializable;

/**
 * Metadata indicating read model data freshness, replication lag, and consistency status.
 */
final class DataFreshnessMetadata implements JsonSerializable
{
    public function __construct(
        private readonly string $asOfTimestamp,
        private readonly string $lastAggregatedAt,
        private readonly int $lagSeconds,
        private readonly DataFreshnessStatus $status,
        private readonly bool $isConsistent,
        private readonly string $notice
    ) {
    }

    public function getAsOfTimestamp(): string
    {
        return $this->asOfTimestamp;
    }

    public function getLastAggregatedAt(): string
    {
        return $this->lastAggregatedAt;
    }

    public function getLagSeconds(): int
    {
        return $this->lagSeconds;
    }

    public function getStatus(): DataFreshnessStatus
    {
        return $this->status;
    }

    public function isConsistent(): bool
    {
        return $this->isConsistent;
    }

    public function getNotice(): string
    {
        return $this->notice;
    }

    public function toArray(): array
    {
        return [
            'as_of_timestamp' => $this->asOfTimestamp,
            'last_aggregated_at' => $this->lastAggregatedAt,
            'lag_seconds' => $this->lagSeconds,
            'status' => $this->status->value,
            'is_consistent' => $this->isConsistent,
            'notice' => $this->notice,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
