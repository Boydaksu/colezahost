<?php

declare(strict_types=1);

namespace Coleza\Foundation\Lock;

use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ConflictException;
use Throwable;

final class DatabaseLock implements LockInterface
{
    private string $table = 'locks';

    public function __construct(private Connection $db)
    {
    }

    public function ensureLocksTable(): void
    {
        $driver = $this->db->getDriverName();
        $pkCol = match ($driver) {
            'sqlite' => 'TEXT PRIMARY KEY',
            default => 'VARCHAR(255) PRIMARY KEY',
        };

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                resource %s,
                owner VARCHAR(255) NOT NULL,
                expires_at INT NOT NULL
            )',
            $this->table,
            $pkCol
        );

        $this->db->statement($sql);
    }

    public function acquire(string $resource, int $ttlSeconds = 60, ?string $owner = null): bool
    {
        $this->ensureLocksTable();
        $owner = $owner ?: bin2hex(random_bytes(8));
        $now = time();
        $expiresAt = $now + $ttlSeconds;

        // Clean up expired lock if present
        $this->db->delete($this->table, 'resource = :res AND expires_at < :now', [
            'res' => $resource,
            'now' => $now,
        ]);

        try {
            $this->db->insert($this->table, [
                'resource' => $resource,
                'owner' => $owner,
                'expires_at' => $expiresAt,
            ]);
            return true;
        } catch (Throwable) {
            // Lock is held by another process
            return false;
        }
    }

    public function release(string $resource, ?string $owner = null): bool
    {
        $this->ensureLocksTable();

        if ($owner !== null) {
            $deleted = $this->db->delete($this->table, 'resource = :res AND owner = :owner', [
                'res' => $resource,
                'owner' => $owner,
            ]);
        } else {
            $deleted = $this->db->delete($this->table, 'resource = :res', ['res' => $resource]);
        }

        return $deleted > 0;
    }

    public function isLocked(string $resource): bool
    {
        $this->ensureLocksTable();

        $row = $this->db->selectOne(
            sprintf('SELECT expires_at FROM %s WHERE resource = :res', $this->table),
            ['res' => $resource]
        );

        if ($row === null) {
            return false;
        }

        if ((int) $row['expires_at'] < time()) {
            $this->release($resource);
            return false;
        }

        return true;
    }

    public function synchronized(string $resource, callable $callback, int $ttlSeconds = 60): mixed
    {
        $owner = bin2hex(random_bytes(16));

        if (!$this->acquire($resource, $ttlSeconds, $owner)) {
            throw new ConflictException(sprintf('Resource [%s] is locked by another process.', $resource));
        }

        try {
            return $callback();
        } finally {
            $this->release($resource, $owner);
        }
    }
}
