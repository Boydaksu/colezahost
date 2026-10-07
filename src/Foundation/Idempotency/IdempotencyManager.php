<?php

declare(strict_types=1);

namespace Coleza\Foundation\Idempotency;

use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ConflictException;
use Throwable;

final class IdempotencyManager
{
    private string $table = 'idempotency_keys';

    public function __construct(private Connection $db)
    {
    }

    public function ensureTable(): void
    {
        $driver = $this->db->getDriverName();
        $pkCol = match ($driver) {
            'sqlite' => 'TEXT PRIMARY KEY',
            default => 'VARCHAR(255) PRIMARY KEY',
        };

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                idempotency_key %s,
                status VARCHAR(50) NOT NULL,
                response LONGTEXT NULL,
                expires_at INT NOT NULL
            )',
            $this->table,
            $pkCol
        );

        $this->db->statement($sql);
    }

    /**
     * Execute an action idempotently, returning cached response if already completed.
     *
     * @template T
     * @param callable(): T $action
     * @return T
     * @throws ConflictException
     */
    public function execute(string $key, callable $action, int $ttlSeconds = 86400): mixed
    {
        $this->ensureTable();
        $now = time();
        $expiresAt = $now + $ttlSeconds;

        // Clean expired key
        $this->db->delete($this->table, 'idempotency_key = :k AND expires_at < :now', [
            'k' => $key,
            'now' => $now,
        ]);

        $existing = $this->db->selectOne(
            sprintf('SELECT status, response FROM %s WHERE idempotency_key = :k', $this->table),
            ['k' => $key]
        );

        if ($existing !== null) {
            if ($existing['status'] === 'IN_PROGRESS') {
                throw new ConflictException(sprintf('Concurrent operation in progress for key [%s].', $key));
            }
            if ($existing['status'] === 'COMPLETED') {
                return unserialize((string) $existing['response']);
            }
        }

        // Reserve key as IN_PROGRESS
        try {
            $this->db->insert($this->table, [
                'idempotency_key' => $key,
                'status' => 'IN_PROGRESS',
                'response' => null,
                'expires_at' => $expiresAt,
            ]);
        } catch (Throwable) {
            throw new ConflictException(sprintf('Concurrent operation conflict for key [%s].', $key));
        }

        try {
            $result = $action();

            $this->db->update(
                $this->table,
                [
                    'status' => 'COMPLETED',
                    'response' => serialize($result),
                ],
                'idempotency_key = :where_k',
                ['where_k' => $key]
            );

            return $result;
        } catch (Throwable $e) {
            // Delete reservation on error so it can be safely retried
            $this->db->delete($this->table, 'idempotency_key = :k', ['k' => $key]);
            throw $e;
        }
    }
}
