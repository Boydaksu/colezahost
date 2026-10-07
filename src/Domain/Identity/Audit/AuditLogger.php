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
            'user_agent' => $userAgent,
            'payload' => !empty($payload) ? json_encode($payload, JSON_UNESCAPED_SLASHES) : null,
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
        return $this->db->select(
            sprintf(
                'SELECT * FROM %s WHERE actor_user_id = :uid OR impersonator_user_id = :uid ORDER BY id DESC',
                $this->table
            ),
            ['uid' => $userId]
        );
    }
}
