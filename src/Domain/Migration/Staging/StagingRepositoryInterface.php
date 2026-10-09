<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Staging;

interface StagingRepositoryInterface
{
    public function ensureTable(): void;

    public function save(StagingRecord $record): void;

    public function find(int $id): ?StagingRecord;

    public function findBySourceEntity(string $batchId, string $sourceEntityType, string $sourceEntityId): ?StagingRecord;

    /**
     * @return list<StagingRecord>
     */
    public function getBatchRecords(string $batchId, ?StagingRecordStatus $status = null): array;

    /**
     * @param list<string> $errors
     */
    public function updateStatus(
        int $id,
        StagingRecordStatus $status,
        ?string $reason = null,
        array $errors = []
    ): void;

    public function generateAccountingReport(string $batchId): StagingAccountingReport;
}
