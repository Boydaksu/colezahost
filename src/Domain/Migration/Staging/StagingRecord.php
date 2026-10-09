<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Staging;

use JsonSerializable;

/**
 * Encapsulates an individual record undergoing migration staging.
 * Preserves the pristine raw source payload to guarantee zero silent loss.
 */
final class StagingRecord implements JsonSerializable
{
    /**
     * @param array<string, mixed> $rawPayload
     * @param array<string, mixed>|null $canonicalPayload
     * @param list<string> $validationErrors
     */
    public function __construct(
        private ?int $id,
        private string $batchId,
        private string $sourceSystem,
        private string $sourceEntityType,
        private string $sourceEntityId,
        private array $rawPayload,
        private StagingRecordStatus $status = StagingRecordStatus::STAGED,
        private ?string $canonicalEntityType = null,
        private ?array $canonicalPayload = null,
        private array $validationErrors = [],
        private ?string $quarantineReason = null,
        private ?int $targetEntityId = null,
        private ?string $processedAt = null,
        private ?string $createdAt = null
    ) {
    }

    public static function createNew(
        string $batchId,
        string $sourceSystem,
        string $sourceEntityType,
        string $sourceEntityId,
        array $rawPayload
    ): self {
        return new self(
            id: null,
            batchId: $batchId,
            sourceSystem: $sourceSystem,
            sourceEntityType: $sourceEntityType,
            sourceEntityId: $sourceEntityId,
            rawPayload: $rawPayload,
            status: StagingRecordStatus::STAGED,
            createdAt: date('Y-m-d H:i:s')
        );
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getBatchId(): string
    {
        return $this->batchId;
    }

    public function getSourceSystem(): string
    {
        return $this->sourceSystem;
    }

    public function getSourceEntityType(): string
    {
        return $this->sourceEntityType;
    }

    public function getSourceEntityId(): string
    {
        return $this->sourceEntityId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRawPayload(): array
    {
        return $this->rawPayload;
    }

    public function getStatus(): StagingRecordStatus
    {
        return $this->status;
    }

    public function getCanonicalEntityType(): ?string
    {
        return $this->canonicalEntityType;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCanonicalPayload(): ?array
    {
        return $this->canonicalPayload;
    }

    /**
     * @return list<string>
     */
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    public function getQuarantineReason(): ?string
    {
        return $this->quarantineReason;
    }

    public function getTargetEntityId(): ?int
    {
        return $this->targetEntityId;
    }

    public function getProcessedAt(): ?string
    {
        return $this->processedAt;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    /**
     * @param array<string, mixed> $canonicalPayload
     */
    public function markTransformed(string $canonicalEntityType, array $canonicalPayload): void
    {
        $this->canonicalEntityType = $canonicalEntityType;
        $this->canonicalPayload = $canonicalPayload;
        $this->status = StagingRecordStatus::TRANSFORMED;
    }

    public function markValidated(): void
    {
        $this->status = StagingRecordStatus::VALIDATED;
        $this->validationErrors = [];
    }

    /**
     * @param list<string> $errors
     */
    public function markQuarantined(string $reason, array $errors = []): void
    {
        $this->status = StagingRecordStatus::QUARANTINED;
        $this->quarantineReason = $reason;
        $this->validationErrors = $errors;
        $this->processedAt = date('Y-m-d H:i:s');
    }

    public function markSkippedUnsupported(string $reason): void
    {
        $this->status = StagingRecordStatus::SKIPPED_UNSUPPORTED;
        $this->quarantineReason = $reason;
        $this->processedAt = date('Y-m-d H:i:s');
    }

    public function markMigrated(int $targetEntityId): void
    {
        $this->status = StagingRecordStatus::MIGRATED;
        $this->targetEntityId = $targetEntityId;
        $this->processedAt = date('Y-m-d H:i:s');
    }

    public function markFailed(string $error): void
    {
        $this->status = StagingRecordStatus::FAILED;
        $this->quarantineReason = $error;
        $this->validationErrors[] = $error;
        $this->processedAt = date('Y-m-d H:i:s');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'batch_id' => $this->batchId,
            'source_system' => $this->sourceSystem,
            'source_entity_type' => $this->sourceEntityType,
            'source_entity_id' => $this->sourceEntityId,
            'raw_payload' => $this->rawPayload,
            'status' => $this->status->value,
            'canonical_entity_type' => $this->canonicalEntityType,
            'canonical_payload' => $this->canonicalPayload,
            'validation_errors' => $this->validationErrors,
            'quarantine_reason' => $this->quarantineReason,
            'target_entity_id' => $this->targetEntityId,
            'processed_at' => $this->processedAt,
            'created_at' => $this->createdAt,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
