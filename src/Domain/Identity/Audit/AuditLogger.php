<?php

declare(strict_types=1);

namespace Coleza\Domain\Identity\Audit;

use Coleza\Foundation\Database\Connection;

final class AuditLogger
{
    private string $table = 'security_audit_logs';

    public function __construct(private Connection $db)
    {
    }

    public function ensureTable(): void
    {
        // Keep legacy SQLite transactional bootstrap used by migration 9.
        // MySQL implicit-commit DDL remains prohibited in caller transactions.
        if (\Coleza\Foundation\Database\PdoSchema::useInstalledSchema($this->db, [$this->table => ['id', 'actor_user_id', 'impersonator_user_id', 'event_type', 'target_resource', 'ip_address', 'user_agent', 'payload', 'created_at']], installSchema: true)) { return; }
        \Coleza\Foundation\Database\PdoSchema::autoIncrement($this->db->getPdo());
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                actor_user_id INT NOT NULL,
                impersonator_user_id INT NULL,
                event_type VARCHAR(100) NOT NULL,
                target_resource VARCHAR(100) NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                payload LONGTEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->table,
            $autoInc
        );

        $this->db->statement($sql);
    }

    /**
     * Write an immutable security audit event.
     *
     * @param array<string, mixed> $payload
     */
    public function log(
        int $actorUserId,
        string $eventType,
        ?string $targetResource = null,
        ?int $impersonatorUserId = null,
        ?string $ip = null,
        ?string $userAgent = null,
        array $payload = []
    ): int {
        $this->ensureTable();

        return (int) $this->db->insert($this->table, [
            'actor_user_id' => $actorUserId,
            'impersonator_user_id' => $impersonatorUserId,
            'event_type' => $eventType,
            'target_resource' => $targetResource,
            'ip_address' => $ip,
            'user_agent' => $userAgent === null ? null : \Coleza\Foundation\Security\SecretRedactor::text($userAgent),
            'payload' => !empty($payload) ? json_encode(\Coleza\Foundation\Security\SecretRedactor::sanitize($payload), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES) : null,
        ]);
    }

    /**
     * Query audit logs for a specific actor or impersonator.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getLogsForUser(int $userId): array
    {
        $this->ensureTable();
        $rows = $this->db->select(
            sprintf(
                'SELECT * FROM %s WHERE actor_user_id = :actor_uid OR impersonator_user_id = :impersonator_uid ORDER BY id DESC',
                $this->table
            ),
            ['actor_uid' => $userId, 'impersonator_uid' => $userId]
        );
        foreach ($rows as &$row) {
            foreach (['payload', 'user_agent'] as $field) { if (is_string($row[$field] ?? null)) { $row[$field] = \Coleza\Foundation\Security\SecretRedactor::text($row[$field]); } }
        }
        return $rows;
    }
}
