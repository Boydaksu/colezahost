<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Execution;

use Coleza\Foundation\Database\Connection;

/**
 * Persistence repository for migration batch checkpoints.
 */
final class MigrationCheckpointRepository
{
    private string $table = 'migration_checkpoints';

    public function __construct(
        private Connection $db
    ) {
    }

    public function ensureTable(): void
    {
        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                batch_id VARCHAR(64) PRIMARY KEY,
                current_step VARCHAR(64) NOT NULL,
                last_processed_id VARCHAR(64) NOT NULL DEFAULT "0",
                processed_count INT NOT NULL DEFAULT 0,
                total_count INT NOT NULL DEFAULT 0,
                status VARCHAR(32) NOT NULL DEFAULT "in_progress",
                metadata_json TEXT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->table
        );

        $this->db->statement($sql);
    }

    public function save(MigrationCheckpoint $checkpoint): void
    {
        $this->ensureTable();

        $existing = $this->find($checkpoint->getBatchId());
        $metaJson = json_encode($checkpoint->getMetadata(), JSON_UNESCAPED_UNICODE);

        if ($existing !== null) {
            $this->db->statement(
                sprintf(
                    'UPDATE %s
                    SET current_step = ?, last_processed_id = ?, processed_count = ?,
                        total_count = ?, status = ?, metadata_json = ?, updated_at = ?
                    WHERE batch_id = ?',
                    $this->table
                ),
                [
                    $checkpoint->getCurrentStep(),
                    $checkpoint->getLastProcessedId(),
                    $checkpoint->getProcessedCount(),
                    $checkpoint->getTotalCount(),
                    $checkpoint->getStatus(),
                    $metaJson,
                    $checkpoint->getUpdatedAt(),
                    $checkpoint->getBatchId(),
                ]
            );
        } else {
            $this->db->statement(
                sprintf(
                    'INSERT INTO %s
                    (batch_id, current_step, last_processed_id, processed_count,
                     total_count, status, metadata_json, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    $this->table
                ),
                [
                    $checkpoint->getBatchId(),
                    $checkpoint->getCurrentStep(),
                    $checkpoint->getLastProcessedId(),
                    $checkpoint->getProcessedCount(),
                    $checkpoint->getTotalCount(),
                    $checkpoint->getStatus(),
                    $metaJson,
                    $checkpoint->getUpdatedAt(),
                ]
            );
        }
    }

    public function find(string $batchId): ?MigrationCheckpoint
    {
        $this->ensureTable();

        $row = $this->db->selectOne(
            sprintf('SELECT * FROM %s WHERE batch_id = ?', $this->table),
            [$batchId]
        );

        if ($row === null) {
            return null;
        }

        $meta = !empty($row['metadata_json']) ? json_decode((string) $row['metadata_json'], true) : [];

        return new MigrationCheckpoint(
            batchId: (string) $row['batch_id'],
            currentStep: (string) $row['current_step'],
            lastProcessedId: (string) $row['last_processed_id'],
            processedCount: (int) $row['processed_count'],
            totalCount: (int) $row['total_count'],
            status: (string) $row['status'],
            metadata: is_array($meta) ? $meta : [],
            updatedAt: (string) $row['updated_at']
        );
    }

    public function delete(string $batchId): void
    {
        $this->ensureTable();

        $this->db->statement(
            sprintf('DELETE FROM %s WHERE batch_id = ?', $this->table),
            [$batchId]
        );
    }
}
