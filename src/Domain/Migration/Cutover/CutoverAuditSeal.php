<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Cutover;

use JsonSerializable;

/**
 * Immutable, tamper-evident audit seal certifying that a migration batch
 * has successfully completed all pre-flight, reconciliation, and zero silent data loss checks.
 */
final class CutoverAuditSeal implements JsonSerializable
{
    /**
     * @param array<string, mixed> $stagingSummary
     * @param array<string, mixed> $financialSummary
     * @param array<string, mixed> $entityCounts
     */
    public function __construct(
        private string $batchId,
        private string $approvedBy,
        private int $totalEntitiesMigrated,
        private array $entityCounts,
        private array $stagingSummary,
        private array $financialSummary,
        private int $unsupportedFieldsCount,
        private string $sha256Checksum,
        private ?string $sealedAt = null,
        private string $status = 'SEALED',
        private ?string $signature = null
    ) {
        $this->sealedAt ??= date('c');
    }

    public static function create(
        string $batchId,
        string $approvedBy,
        int $totalEntitiesMigrated,
        array $entityCounts,
        array $stagingSummary,
        array $financialSummary,
        int $unsupportedFieldsCount,
        ?string $signature = null
    ): self {
        $dataForHash = [
            'batch_id' => $batchId,
            'approved_by' => $approvedBy,
            'total_migrated' => $totalEntitiesMigrated,
            'entity_counts' => $entityCounts,
            'staging' => $stagingSummary,
            'financial' => $financialSummary,
            'unsupported_fields' => $unsupportedFieldsCount,
        ];

        $checksum = hash('sha256', (string) json_encode($dataForHash, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return new self(
            batchId: $batchId,
            approvedBy: $approvedBy,
            totalEntitiesMigrated: $totalEntitiesMigrated,
            entityCounts: $entityCounts,
            stagingSummary: $stagingSummary,
            financialSummary: $financialSummary,
            unsupportedFieldsCount: $unsupportedFieldsCount,
            sha256Checksum: $checksum,
            sealedAt: date('c'),
            status: 'SEALED',
            signature: $signature
        );
    }

    public function getBatchId(): string
    {
        return $this->batchId;
    }

    public function getApprovedBy(): string
    {
        return $this->approvedBy;
    }

    public function getTotalEntitiesMigrated(): int
    {
        return $this->totalEntitiesMigrated;
    }

    /**
     * @return array<string, mixed>
     */
    public function getEntityCounts(): array
    {
        return $this->entityCounts;
    }

    /**
     * @return array<string, mixed>
     */
    public function getStagingSummary(): array
    {
        return $this->stagingSummary;
    }

    /**
     * @return array<string, mixed>
     */
    public function getFinancialSummary(): array
    {
        return $this->financialSummary;
    }

    public function getUnsupportedFieldsCount(): int
    {
        return $this->unsupportedFieldsCount;
    }

    public function getSha256Checksum(): string
    {
        return $this->sha256Checksum;
    }

    public function getSealedAt(): string
    {
        return $this->sealedAt ?? date('c');
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getSignature(): ?string
    {
        return $this->signature;
    }

    public function verifyChecksum(): bool
    {
        $dataForHash = [
            'batch_id' => $this->batchId,
            'approved_by' => $this->approvedBy,
            'total_migrated' => $this->totalEntitiesMigrated,
            'entity_counts' => $this->entityCounts,
            'staging' => $this->stagingSummary,
            'financial' => $this->financialSummary,
            'unsupported_fields' => $this->unsupportedFieldsCount,
        ];

        $computed = hash('sha256', (string) json_encode($dataForHash, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return hash_equals($this->sha256Checksum, $computed);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'batch_id' => $this->batchId,
            'approved_by' => $this->approvedBy,
            'total_entities_migrated' => $this->totalEntitiesMigrated,
            'entity_counts' => $this->entityCounts,
            'staging_summary' => $this->stagingSummary,
            'financial_summary' => $this->financialSummary,
            'unsupported_fields_count' => $this->unsupportedFieldsCount,
            'sha256_checksum' => $this->sha256Checksum,
            'sealed_at' => $this->sealedAt,
            'status' => $this->status,
            'signature' => $this->signature,
            'checksum_valid' => $this->verifyChecksum(),
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
