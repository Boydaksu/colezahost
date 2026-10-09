<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Hold;

use Coleza\Foundation\Database\Connection;

/**
 * Persistence repository for migration holds on entities and batches.
 */
final class MigrationHoldRepository
{
    private string $table = 'migration_holds';

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
                entity_type VARCHAR(64) NOT NULL,
                entity_id VARCHAR(64) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT "ACTIVE",
                reason VARCHAR(255) NOT NULL,
                suppressed_actions_json TEXT NULL,
                held_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                released_at TIMESTAMP NULL,
                released_by VARCHAR(64) NULL,
                release_notes TEXT NULL
            )',
            $this->table,
            $autoInc
        );

        $this->db->statement($sql);
    }

    public function save(MigrationHoldRecord $record): MigrationHoldRecord
    {
        $this->ensureTable();

        $actionsJson = json_encode($record->getSuppressedActions(), JSON_UNESCAPED_UNICODE);

        if ($record->getId() !== null) {
            $this->db->statement(
                sprintf(
                    'UPDATE %s
                    SET status = ?, reason = ?, suppressed_actions_json = ?,
                        released_at = ?, released_by = ?, release_notes = ?
                    WHERE id = ?',
                    $this->table
                ),
                [
                    $record->getStatus()->value,
                    $record->getReason(),
                    $actionsJson,
                    $record->getReleasedAt(),
                    $record->getReleasedBy(),
                    $record->getReleaseNotes(),
                    $record->getId(),
                ]
            );
            return $record;
        }

        // Check if existing record exists for batch + entity
        $existing = $this->db->selectOne(
            sprintf(
                'SELECT id FROM %s WHERE batch_id = ? AND entity_type = ? AND entity_id = ?',
                $this->table
            ),
            [$record->getBatchId(), $record->getEntityType(), $record->getEntityId()]
        );

        if ($existing !== null) {
            $id = (int) $existing['id'];
            $record->setId($id);
            $this->db->statement(
                sprintf(
                    'UPDATE %s
                    SET status = ?, reason = ?, suppressed_actions_json = ?,
                        released_at = ?, released_by = ?, release_notes = ?
                    WHERE id = ?',
                    $this->table
                ),
                [
                    $record->getStatus()->value,
                    $record->getReason(),
                    $actionsJson,
                    $record->getReleasedAt(),
                    $record->getReleasedBy(),
                    $record->getReleaseNotes(),
                    $id,
                ]
            );
            return $record;
        }

        $this->db->statement(
            sprintf(
                'INSERT INTO %s
                (batch_id, entity_type, entity_id, status, reason, suppressed_actions_json, held_at, released_at, released_by, release_notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                $this->table
            ),
            [
                $record->getBatchId(),
                $record->getEntityType(),
                $record->getEntityId(),
                $record->getStatus()->value,
                $record->getReason(),
                $actionsJson,
                $record->getHeldAt(),
                $record->getReleasedAt(),
                $record->getReleasedBy(),
                $record->getReleaseNotes(),
            ]
        );

        $insertedId = (int) $this->db->getPdo()->lastInsertId();
        $record->setId($insertedId);

        return $record;
    }

    public function findActive(string $entityType, int|string $entityId): ?MigrationHoldRecord
    {
        $this->ensureTable();

        $row = $this->db->selectOne(
            sprintf(
                'SELECT * FROM %s WHERE entity_type = ? AND entity_id = ? AND status = "ACTIVE" ORDER BY id DESC LIMIT 1',
                $this->table
            ),
            [strtolower(trim($entityType)), (string) $entityId]
        );

        if ($row === null) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function findGlobalHold(?string $batchId = null): ?MigrationHoldRecord
    {
        $this->ensureTable();

        if ($batchId !== null) {
            $row = $this->db->selectOne(
                sprintf(
                    'SELECT * FROM %s WHERE entity_type = "GLOBAL" AND batch_id = ? AND status = "ACTIVE" LIMIT 1',
                    $this->table
                ),
                [$batchId]
            );
        } else {
            $row = $this->db->selectOne(
                sprintf(
                    'SELECT * FROM %s WHERE entity_type = "GLOBAL" AND status = "ACTIVE" ORDER BY id DESC LIMIT 1',
                    $this->table
                )
            );
        }

        if ($row === null) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * @return list<MigrationHoldRecord>
     */
    public function findByBatch(string $batchId, ?MigrationHoldStatus $status = null): array
    {
        $this->ensureTable();

        if ($status !== null) {
            $rows = $this->db->select(
                sprintf('SELECT * FROM %s WHERE batch_id = ? AND status = ? ORDER BY id ASC', $this->table),
                [$batchId, $status->value]
            );
        } else {
            $rows = $this->db->select(
                sprintf('SELECT * FROM %s WHERE batch_id = ? ORDER BY id ASC', $this->table),
                [$batchId]
            );
        }

        $records = [];
        foreach ($rows as $row) {
            $records[] = $this->hydrate($row);
        }

        return $records;
    }

    public function releaseEntity(string $entityType, int|string $entityId, string $releasedBy, ?string $notes = null): bool
    {
        $this->ensureTable();

        $active = $this->findActive($entityType, $entityId);
        if ($active === null) {
            return false;
        }

        $active->release($releasedBy, $notes);
        $this->save($active);

        return true;
    }

    public function releaseBatch(string $batchId, string $releasedBy, ?string $notes = null): int
    {
        $this->ensureTable();

        $activeRecords = $this->findByBatch($batchId, MigrationHoldStatus::ACTIVE);
        $count = 0;

        foreach ($activeRecords as $record) {
            $record->release($releasedBy, $notes);
            $this->save($record);
            $count++;
        }

        return $count;
    }

    public function releaseGlobalHold(string $batchId, string $releasedBy, ?string $notes = null): bool
    {
        $this->ensureTable();

        $globalHold = $this->findGlobalHold($batchId);
        if ($globalHold === null) {
            return false;
        }

        $globalHold->release($releasedBy, $notes);
        $this->save($globalHold);

        return true;
    }

    /**
     * @return array{total: int, active: int, released: int, by_type: array<string, array{active: int, released: int}>}
     */
    public function getSummary(string $batchId): array
    {
        $this->ensureTable();

        $records = $this->findByBatch($batchId);
        $total = count($records);
        $active = 0;
        $released = 0;
        $byType = [];

        foreach ($records as $r) {
            $type = $r->getEntityType();
            if (!isset($byType[$type])) {
                $byType[$type] = ['active' => 0, 'released' => 0];
            }

            if ($r->isActive()) {
                $active++;
                $byType[$type]['active']++;
            } else {
                $released++;
                $byType[$type]['released']++;
            }
        }

        return [
            'total' => $total,
            'active' => $active,
            'released' => $released,
            'by_type' => $byType,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): MigrationHoldRecord
    {
        $actions = [];
        if (!empty($row['suppressed_actions_json'])) {
            $decoded = json_decode((string) $row['suppressed_actions_json'], true);
            if (is_array($decoded)) {
                $actions = array_values(array_map('strval', $decoded));
            }
        }

        return new MigrationHoldRecord(
            batchId: (string) $row['batch_id'],
            entityType: (string) $row['entity_type'],
            entityId: (string) $row['entity_id'],
            reason: (string) $row['reason'],
            status: MigrationHoldStatus::from((string) $row['status']),
            suppressedActions: $actions,
            heldAt: (string) $row['held_at'],
            releasedAt: $row['released_at'] !== null ? (string) $row['released_at'] : null,
            releasedBy: $row['released_by'] !== null ? (string) $row['released_by'] : null,
            releaseNotes: $row['release_notes'] !== null ? (string) $row['release_notes'] : null,
            id: (int) $row['id']
        );
    }
}
