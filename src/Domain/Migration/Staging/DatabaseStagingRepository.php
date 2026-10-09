<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Staging;

use Coleza\Foundation\Database\Connection;

final class DatabaseStagingRepository implements StagingRepositoryInterface
{
    private string $table = 'migration_staging_records';

    public function __construct(
        private Connection $db
    ) {
    }

    public function ensureTable(): void
    {
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                batch_id VARCHAR(64) NOT NULL,
                source_system VARCHAR(32) NOT NULL,
                source_entity_type VARCHAR(64) NOT NULL,
                source_entity_id VARCHAR(128) NOT NULL,
                raw_payload_json TEXT NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT "staged",
                canonical_entity_type VARCHAR(64) NULL,
                canonical_payload_json TEXT NULL,
                validation_errors_json TEXT NULL,
                quarantine_reason TEXT NULL,
                target_entity_id INT NULL,
                processed_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (batch_id, source_entity_type, source_entity_id)
            )',
            $this->table,
            $autoInc
        );

        $this->db->statement($sql);
    }

    public function save(StagingRecord $record): void
    {
        $this->ensureTable();

        $rawJson = json_encode($record->getRawPayload(), JSON_THROW_ON_ERROR);
        $canonicalJson = $record->getCanonicalPayload() !== null
            ? json_encode($record->getCanonicalPayload(), JSON_THROW_ON_ERROR)
            : null;
        $errorsJson = json_encode($record->getValidationErrors(), JSON_THROW_ON_ERROR);

        if ($record->getId() === null) {
            $existing = $this->findBySourceEntity(
                $record->getBatchId(),
                $record->getSourceEntityType(),
                $record->getSourceEntityId()
            );
            if ($existing !== null) {
                $record->setId($existing->getId());
            }
        }

        if ($record->getId() === null) {
            $sql = sprintf(
                'INSERT INTO %s
                (batch_id, source_system, source_entity_type, source_entity_id, raw_payload_json, status,
                 canonical_entity_type, canonical_payload_json, validation_errors_json, quarantine_reason,
                 target_entity_id, processed_at, created_at)
                VALUES
                (:b, :sys, :set, :sid, :raw, :st, :cet, :can, :err, :qr, :tid, :pr, :cr)',
                $this->table
            );

            $this->db->statement($sql, [
                'b' => $record->getBatchId(),
                'sys' => $record->getSourceSystem(),
                'set' => $record->getSourceEntityType(),
                'sid' => $record->getSourceEntityId(),
                'raw' => $rawJson,
                'st' => $record->getStatus()->value,
                'cet' => $record->getCanonicalEntityType(),
                'can' => $canonicalJson,
                'err' => $errorsJson,
                'qr' => $record->getQuarantineReason(),
                'tid' => $record->getTargetEntityId(),
                'pr' => $record->getProcessedAt(),
                'cr' => $record->getCreatedAt() ?? date('Y-m-d H:i:s'),
            ]);

            $id = (int) $this->db->getPdo()->lastInsertId();
            $record->setId($id);
        } else {
            $sql = sprintf(
                'UPDATE %s
                 SET status = :st,
                     canonical_entity_type = :cet,
                     canonical_payload_json = :can,
                     validation_errors_json = :err,
                     quarantine_reason = :qr,
                     target_entity_id = :tid,
                     processed_at = :pr
                 WHERE id = :id',
                $this->table
            );

            $this->db->statement($sql, [
                'id' => $record->getId(),
                'st' => $record->getStatus()->value,
                'cet' => $record->getCanonicalEntityType(),
                'can' => $canonicalJson,
                'err' => $errorsJson,
                'qr' => $record->getQuarantineReason(),
                'tid' => $record->getTargetEntityId(),
                'pr' => $record->getProcessedAt(),
            ]);
        }
    }

    public function find(int $id): ?StagingRecord
    {
        $this->ensureTable();

        $rows = $this->db->select(
            sprintf('SELECT * FROM %s WHERE id = :id', $this->table),
            ['id' => $id]
        );

        if (empty($rows)) {
            return null;
        }

        return $this->mapRowToRecord($rows[0]);
    }

    public function findBySourceEntity(string $batchId, string $sourceEntityType, string $sourceEntityId): ?StagingRecord
    {
        $this->ensureTable();

        $rows = $this->db->select(
            sprintf(
                'SELECT * FROM %s WHERE batch_id = :b AND source_entity_type = :set AND source_entity_id = :sid',
                $this->table
            ),
            ['b' => $batchId, 'set' => $sourceEntityType, 'sid' => $sourceEntityId]
        );

        if (empty($rows)) {
            return null;
        }

        return $this->mapRowToRecord($rows[0]);
    }

    /**
     * @return list<StagingRecord>
     */
    public function getBatchRecords(string $batchId, ?StagingRecordStatus $status = null): array
    {
        $this->ensureTable();

        if ($status !== null) {
            $rows = $this->db->select(
                sprintf('SELECT * FROM %s WHERE batch_id = :b AND status = :st ORDER BY id ASC', $this->table),
                ['b' => $batchId, 'st' => $status->value]
            );
        } else {
            $rows = $this->db->select(
                sprintf('SELECT * FROM %s WHERE batch_id = :b ORDER BY id ASC', $this->table),
                ['b' => $batchId]
            );
        }

        return array_map(fn (array $r) => $this->mapRowToRecord($r), $rows);
    }

    /**
     * @param list<string> $errors
     */
    public function updateStatus(
        int $id,
        StagingRecordStatus $status,
        ?string $reason = null,
        array $errors = []
    ): void {
        $this->ensureTable();

        $this->db->statement(
            sprintf(
                'UPDATE %s
                 SET status = :st,
                     quarantine_reason = :qr,
                     validation_errors_json = :err,
                     processed_at = :pr
                 WHERE id = :id',
                $this->table
            ),
            [
                'id' => $id,
                'st' => $status->value,
                'qr' => $reason,
                'err' => json_encode($errors, JSON_THROW_ON_ERROR),
                'pr' => date('Y-m-d H:i:s'),
            ]
        );
    }

    public function generateAccountingReport(string $batchId): StagingAccountingReport
    {
        $this->ensureTable();

        $rows = $this->db->select(
            sprintf('SELECT status, COUNT(*) as cnt FROM %s WHERE batch_id = :b GROUP BY status', $this->table),
            ['b' => $batchId]
        );

        $statusCounts = [];
        $total = 0;
        foreach ($rows as $r) {
            $st = (string) $r['status'];
            $cnt = (int) $r['cnt'];
            $statusCounts[$st] = $cnt;
            $total += $cnt;
        }

        $migrated = $statusCounts[StagingRecordStatus::MIGRATED->value] ?? 0;
        $quarantined = $statusCounts[StagingRecordStatus::QUARANTINED->value] ?? 0;
        $skipped = $statusCounts[StagingRecordStatus::SKIPPED_UNSUPPORTED->value] ?? 0;
        $failed = $statusCounts[StagingRecordStatus::FAILED->value] ?? 0;

        $inFlight = ($statusCounts[StagingRecordStatus::STAGED->value] ?? 0)
            + ($statusCounts[StagingRecordStatus::VALIDATED->value] ?? 0)
            + ($statusCounts[StagingRecordStatus::TRANSFORMED->value] ?? 0);

        return new StagingAccountingReport(
            batchId: $batchId,
            totalStagedRecords: $total,
            migratedCount: $migrated,
            quarantinedCount: $quarantined,
            skippedCount: $skipped,
            failedCount: $failed,
            inFlightCount: $inFlight,
            statusCounts: $statusCounts
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToRecord(array $row): StagingRecord
    {
        $rawPayload = json_decode((string) $row['raw_payload_json'], true) ?: [];
        $canonicalPayload = $row['canonical_payload_json'] !== null
            ? json_decode((string) $row['canonical_payload_json'], true)
            : null;
        $validationErrors = $row['validation_errors_json'] !== null
            ? json_decode((string) $row['validation_errors_json'], true)
            : [];

        return new StagingRecord(
            id: (int) $row['id'],
            batchId: (string) $row['batch_id'],
            sourceSystem: (string) $row['source_system'],
            sourceEntityType: (string) $row['source_entity_type'],
            sourceEntityId: (string) $row['source_entity_id'],
            rawPayload: $rawPayload,
            status: StagingRecordStatus::from((string) $row['status']),
            canonicalEntityType: $row['canonical_entity_type'] !== null ? (string) $row['canonical_entity_type'] : null,
            canonicalPayload: $canonicalPayload,
            validationErrors: is_array($validationErrors) ? $validationErrors : [],
            quarantineReason: $row['quarantine_reason'] !== null ? (string) $row['quarantine_reason'] : null,
            targetEntityId: $row['target_entity_id'] !== null ? (int) $row['target_entity_id'] : null,
            processedAt: $row['processed_at'] !== null ? (string) $row['processed_at'] : null,
            createdAt: (string) $row['created_at']
        );
    }
}
