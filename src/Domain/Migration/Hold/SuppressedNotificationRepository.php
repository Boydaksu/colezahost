<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Hold;

use Coleza\Foundation\Database\Connection;

/**
 * Persistence repository for notifications suppressed during migration.
 */
final class SuppressedNotificationRepository
{
    private string $table = 'suppressed_notifications';

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
                recipient_email VARCHAR(255) NOT NULL,
                notification_type VARCHAR(128) NOT NULL,
                entity_type VARCHAR(64) NULL,
                entity_id VARCHAR(64) NULL,
                reason VARCHAR(255) NOT NULL,
                payload_json TEXT NULL,
                suppressed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                status VARCHAR(32) NOT NULL DEFAULT "suppressed",
                replayed_at TIMESTAMP NULL
            )',
            $this->table,
            $autoInc
        );

        $this->db->statement($sql);
    }

    public function save(SuppressedNotification $item): SuppressedNotification
    {
        $this->ensureTable();

        $payloadJson = json_encode($item->getPayload(), JSON_UNESCAPED_UNICODE);

        if ($item->getId() !== null) {
            $this->db->statement(
                sprintf(
                    'UPDATE %s
                    SET status = ?, replayed_at = ?, payload_json = ?
                    WHERE id = ?',
                    $this->table
                ),
                [
                    $item->getStatus(),
                    $item->getReplayedAt(),
                    $payloadJson,
                    $item->getId(),
                ]
            );
            return $item;
        }

        $this->db->statement(
            sprintf(
                'INSERT INTO %s
                (batch_id, recipient_email, notification_type, entity_type, entity_id, reason, payload_json, suppressed_at, status, replayed_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                $this->table
            ),
            [
                $item->getBatchId(),
                $item->getRecipientEmail(),
                $item->getNotificationType(),
                $item->getEntityType(),
                $item->getEntityId() !== null ? (string) $item->getEntityId() : null,
                $item->getReason(),
                $payloadJson,
                $item->getSuppressedAt(),
                $item->getStatus(),
                $item->getReplayedAt(),
            ]
        );

        $insertedId = (int) $this->db->getPdo()->lastInsertId();
        $item->setId($insertedId);

        return $item;
    }

    public function find(int $id): ?SuppressedNotification
    {
        $this->ensureTable();

        $row = $this->db->selectOne(
            sprintf('SELECT * FROM %s WHERE id = ?', $this->table),
            [$id]
        );

        if ($row === null) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * @return list<SuppressedNotification>
     */
    public function findByBatch(string $batchId, ?string $status = null): array
    {
        $this->ensureTable();

        if ($status !== null) {
            $rows = $this->db->select(
                sprintf('SELECT * FROM %s WHERE batch_id = ? AND status = ? ORDER BY id ASC', $this->table),
                [$batchId, $status]
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

    public function countByBatch(string $batchId): int
    {
        $this->ensureTable();

        $row = $this->db->selectOne(
            sprintf('SELECT COUNT(*) as cnt FROM %s WHERE batch_id = ?', $this->table),
            [$batchId]
        );

        return (int) ($row['cnt'] ?? 0);
    }

    public function markReplayed(int $id): bool
    {
        $this->ensureTable();

        $item = $this->find($id);
        if ($item === null) {
            return false;
        }

        $item->markReplayed();
        $this->save($item);

        return true;
    }

    /**
     * @return array{total_suppressed: int, replayed: int, pending: int, by_type: array<string, int>}
     */
    public function getSummary(string $batchId): array
    {
        $records = $this->findByBatch($batchId);
        $total = count($records);
        $replayed = 0;
        $pending = 0;
        $byType = [];

        foreach ($records as $r) {
            if ($r->isReplayed()) {
                $replayed++;
            } else {
                $pending++;
            }

            $type = $r->getNotificationType();
            $byType[$type] = ($byType[$type] ?? 0) + 1;
        }

        return [
            'total_suppressed' => $total,
            'replayed' => $replayed,
            'pending' => $pending,
            'by_type' => $byType,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): SuppressedNotification
    {
        $payload = [];
        if (!empty($row['payload_json'])) {
            $decoded = json_decode((string) $row['payload_json'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        return new SuppressedNotification(
            batchId: (string) $row['batch_id'],
            recipientEmail: (string) $row['recipient_email'],
            notificationType: (string) $row['notification_type'],
            reason: (string) $row['reason'],
            entityType: $row['entity_type'] !== null ? (string) $row['entity_type'] : null,
            entityId: $row['entity_id'] !== null ? (string) $row['entity_id'] : null,
            payload: $payload,
            suppressedAt: (string) $row['suppressed_at'],
            status: (string) $row['status'],
            replayedAt: $row['replayed_at'] !== null ? (string) $row['replayed_at'] : null,
            id: (int) $row['id']
        );
    }
}
