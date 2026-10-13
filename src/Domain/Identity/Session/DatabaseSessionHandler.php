<?php

declare(strict_types=1);

namespace Coleza\Domain\Identity\Session;

use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Database\ApiSessionSchema;

final class DatabaseSessionHandler
{
    private string $table = 'sessions';

    public function __construct(
        private Connection $db,
        private int $lifetimeSeconds = 7200
    ) {
        if ($lifetimeSeconds < 1) { throw new \InvalidArgumentException("Session lifetime must be positive."); }
    }

    public function ensureTable(bool $installation = true): void
    {
        if (ApiSessionSchema::installed($this->db, $this->table, $installation)) { return; }
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

        $this->db->statement($sql . ($this->db->getDriverName() === 'mysql' ? ' ENGINE=InnoDB' : ''));
    }

    /**
     * Read session data by session ID.
     *
     * @return array<string, mixed>
     */
    public function read(string $sessionId): array
    {
        $this->validateId($sessionId); $this->ensureTable(installation: false);
        $cutoff = time() - $this->lifetimeSeconds;
        // Delete only rows that are still expired when the write lock is acquired.
        $this->db->delete($this->table, 'id = :id AND last_activity < :cutoff', ['id' => $sessionId, 'cutoff' => $cutoff]);
        $row = $this->db->selectOne('SELECT payload FROM sessions WHERE id = ? AND last_activity >= ?', [$sessionId, $cutoff]);
        if ($row === null) { return []; }
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
        $this->validateId($sessionId);
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->ensureTable(installation: false);
        $bindings = [$sessionId, $userId, $ipAddress, $userAgent, $encoded, time()];
        $sql = 'INSERT INTO sessions (id, user_id, ip_address, user_agent, payload, last_activity) VALUES (?, ?, ?, ?, ?, ?)';
        if ($this->db->getDriverName() === 'mysql') {
            $sql .= ' ON DUPLICATE KEY UPDATE user_id = COALESCE(VALUES(user_id), user_id),
                ip_address = COALESCE(VALUES(ip_address), ip_address), user_agent = COALESCE(VALUES(user_agent), user_agent),
                payload = VALUES(payload), last_activity = VALUES(last_activity)';
        } else {
            $sql .= ' ON CONFLICT(id) DO UPDATE SET user_id = COALESCE(excluded.user_id, sessions.user_id),
                ip_address = COALESCE(excluded.ip_address, sessions.ip_address), user_agent = COALESCE(excluded.user_agent, sessions.user_agent),
                payload = excluded.payload, last_activity = excluded.last_activity';
        }
        $this->db->statement($sql, $bindings);
    }

    /**
     * Destroy a session by ID.
     */
    public function destroy(string $sessionId): void
    {
        $this->validateId($sessionId);
        $this->ensureTable(installation: false);
        $this->db->delete($this->table, 'id = :id', ['id' => $sessionId]);
    }

    /**
     * Invalidate all sessions for a specific user (e.g. password change / logout everywhere).
     */
    public function destroyForUser(int $userId): void
    {
        $this->ensureTable(installation: false);
        $this->db->delete($this->table, 'user_id = :user_id', ['user_id' => $userId]);
    }

    /**
     * Garbage collect expired sessions.
     */
    public function gc(): int
    {
        $this->ensureTable(installation: false);
        $cutoff = time() - $this->lifetimeSeconds;
        return $this->db->delete($this->table, 'last_activity < :cutoff', ['cutoff' => $cutoff]);
    }
    private function validateId(string $id): void
    {
        if ($id === '' || strlen($id) > 128 || str_contains($id, "\0")) { throw new \InvalidArgumentException('Invalid session ID.'); }
    }

}
