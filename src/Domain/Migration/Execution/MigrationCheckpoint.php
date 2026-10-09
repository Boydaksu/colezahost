<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Execution;

use JsonSerializable;

/**
 * Value object capturing the exact resume state and progress of a migration batch.
 */
final class MigrationCheckpoint implements JsonSerializable
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private string $batchId,
        private string $currentStep,
        private string $lastProcessedId = '0',
        private int $processedCount = 0,
        private int $totalCount = 0,
        private string $status = 'in_progress',
        private array $metadata = [],
        private ?string $updatedAt = null
    ) {
        $this->updatedAt ??= date('Y-m-d H:i:s');
    }

    public function getBatchId(): string
    {
        return $this->batchId;
    }

    public function getCurrentStep(): string
    {
        return $this->currentStep;
    }

    public function getLastProcessedId(): string
    {
        return $this->lastProcessedId;
    }

    public function getProcessedCount(): int
    {
        return $this->processedCount;
    }

    public function getTotalCount(): int
    {
        return $this->totalCount;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getUpdatedAt(): string
    {
        return $this->updatedAt ?? date('Y-m-d H:i:s');
    }

    public function advance(string $lastId, int $count = 1): void
    {
        $this->lastProcessedId = $lastId;
        $this->processedCount += $count;
        $this->updatedAt = date('Y-m-d H:i:s');
    }

    public function stepComplete(string $nextStep, int $nextTotal = 0): void
    {
        $this->currentStep = $nextStep;
        $this->lastProcessedId = '0';
        $this->processedCount = 0;
        $this->totalCount = $nextTotal;
        $this->updatedAt = date('Y-m-d H:i:s');
    }

    public function markCompleted(): void
    {
        $this->currentStep = 'completed';
        $this->status = 'completed';
        $this->updatedAt = date('Y-m-d H:i:s');
    }

    public function markFailed(string $error): void
    {
        $this->status = 'failed';
        $this->metadata['last_error'] = $error;
        $this->updatedAt = date('Y-m-d H:i:s');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'batch_id' => $this->batchId,
            'current_step' => $this->currentStep,
            'last_processed_id' => $this->lastProcessedId,
            'processed_count' => $this->processedCount,
            'total_count' => $this->totalCount,
            'status' => $this->status,
            'metadata' => $this->metadata,
            'updated_at' => $this->getUpdatedAt(),
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
