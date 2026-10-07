<?php

declare(strict_types=1);

namespace Coleza\Domain\Identity\Session;

use Coleza\Foundation\Database\Connection;

final class DatabaseSessionHandler
{
    private string $table = 'sessions';

    public function __construct(
        private Connection $db,
        private int $lifetimeSeconds = 7200
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
                id VARCHAR(128) PRIMARY KEY,
                user_id INT NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                payload LONGTEXT NOT NULL,
                last_activity INT NOT NULL
            )',
            $this->table
        );

        $this->db->statement($sql);
    }

    /**
     * Read session data by session ID.
     *
     * @return array<string, mixed>
     */
    public function read(string $sessionId): array
    {
        $this->ensureTable();
        $row = $this->db->selectOne(
            sprintf('SELECT payload, last_activity FROM %s WHERE id = :id', $this->table),
            ['id' => $sessionId]
        );

        if ($row === null) {
            return [];
        }

        // Expired session check
        if ((time() - (int) $row['last_activity']) > $this->lifetimeSeconds) {
            $this->destroy($sessionId);
            return [];
        }

        $decoded = json_decode((string) $row['payload'], true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Write or update session payload.
     *
     * @param array<string, mixed> $payload
     */
    public function write(
        string $sessionId,
        array $payload,
        ?int $userId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): void {
        $this->ensureTable();
        $now = time();
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $existing = $this->db->selectOne(
            sprintf('SELECT id FROM %s WHERE id = :id', $this->table),
            ['id' => $sessionId]
        );

        if ($existing === null) {
            $this->db->insert($this->table, [
                'id' => $sessionId,
                'user_id' => $userId,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'payload' => $encoded,
                'last_activity' => $now,
            ]);
        } else {
            $updates = [
                'payload' => $encoded,
                'last_activity' => $now,
            ];
            if ($userId !== null) {
                $updates['user_id'] = $userId;
            }
            if ($ipAddress !== null) {
                $updates['ip_address'] = $ipAddress;
            }
            if ($userAgent !== null) {
                $updates['user_agent'] = $userAgent;
            }

            $this->db->update(
                $this->table,
                $updates,
                'id = :id',
                ['id' => $sessionId]
            );
        }
    }

    /**
     * Destroy a session by ID.
     */
    public function destroy(string $sessionId): void
    {
        $this->ensureTable();
        $this->db->delete($this->table, 'id = :id', ['id' => $sessionId]);
    }

    /**
     * Invalidate all sessions for a specific user (e.g. password change / logout everywhere).
     */
    public function destroyForUser(int $userId): void
    {
        $this->ensureTable();
        $this->db->delete($this->table, 'user_id = :user_id', ['user_id' => $userId]);
    }

    /**
     * Garbage collect expired sessions.
     */
    public function gc(): int
    {
        $this->ensureTable();
        $cutoff = time() - $this->lifetimeSeconds;
        return $this->db->delete($this->table, 'last_activity < :cutoff', ['cutoff' => $cutoff]);
    }
}
