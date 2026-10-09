<?php

declare(strict_types=1);

namespace Coleza\Domain\Privacy\Erasure;

use DateTimeImmutable;

final class ErasureExecutionResult
{
    /**
     * @param list<string> $anonymizedEntities
     * @param list<string> $deletedEntities
     * @param list<string> $retainedEntities
     */
    public function __construct(
        private readonly int $userId,
        private readonly bool $isSuccess,
        private readonly int $deletedCount,
        private readonly int $anonymizedCount,
        private readonly int $retainedCount,
        private readonly array $anonymizedEntities,
        private readonly array $deletedEntities,
        private readonly array $retainedEntities,
        private readonly string $auditChecksum,
        private readonly DateTimeImmutable $executedAt
    ) {
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function isSuccess(): bool
    {
        return $this->isSuccess;
    }

    public function getDeletedCount(): int
    {
        return $this->deletedCount;
    }

    public function getAnonymizedCount(): int
    {
        return $this->anonymizedCount;
    }

    public function getRetainedCount(): int
    {
        return $this->retainedCount;
    }

    /**
     * @return list<string>
     */
    public function getAnonymizedEntities(): array
    {
        return $this->anonymizedEntities;
    }

    /**
     * @return list<string>
     */
    public function getDeletedEntities(): array
    {
        return $this->deletedEntities;
    }

    /**
     * @return list<string>
     */
    public function getRetainedEntities(): array
    {
        return $this->retainedEntities;
    }

    public function getAuditChecksum(): string
    {
        return $this->auditChecksum;
    }

    public function getExecutedAt(): DateTimeImmutable
    {
        return $this->executedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'is_success' => $this->isSuccess,
            'deleted_count' => $this->deletedCount,
            'anonymized_count' => $this->anonymizedCount,
            'retained_count' => $this->retainedCount,
            'anonymized_entities' => $this->anonymizedEntities,
            'deleted_entities' => $this->deletedEntities,
            'retained_entities' => $this->retainedEntities,
            'audit_checksum' => $this->auditChecksum,
            'executed_at' => $this->executedAt->format('Y-m-d H:i:s'),
        ];
    }
}
