<?php

declare(strict_types=1);

namespace Coleza\Foundation\Cache;

use Coleza\Foundation\Database\Connection;
use DateInterval;
use DateTimeImmutable;

final class DatabaseCache implements CacheInterface
{
    private string $table = 'cache';

    public function __construct(private Connection $db)
    {
    }

    public function ensureCacheTable(): void
    {
        $driver = $this->db->getDriverName();
        $keyCol = match ($driver) {
            'sqlite' => 'TEXT PRIMARY KEY',
            default => 'VARCHAR(255) PRIMARY KEY',
        };

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                cache_key %s,
                cache_value LONGTEXT NOT NULL,
                expires_at INT NULL
            )',
            $this->table,
            $keyCol
        );

        $this->db->statement($sql);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureCacheTable();

        $row = $this->db->selectOne(
            sprintf('SELECT cache_value, expires_at FROM %s WHERE cache_key = :key', $this->table),
            ['key' => $key]
        );

        if ($row === null) {
            return $default;
        }

        $expiresAt = $row['expires_at'] !== null ? (int) $row['expires_at'] : null;
        if ($expiresAt !== null && $expiresAt < time()) {
            $this->delete($key);
            return $default;
        }

        $val = @unserialize((string) $row['cache_value']);
        return $val !== false || $row['cache_value'] === serialize(false) ? $val : $default;
    }

    public function set(string $key, mixed $value, int|DateInterval|null $ttl = null): bool
    {
        $this->ensureCacheTable();
        $expiresAt = $this->calculateExpiration($ttl);
        $serialized = serialize($value);

        // Delete existing entry if present then insert
        $this->delete($key);

        $this->db->insert($this->table, [
            'cache_key' => $key,
            'cache_value' => $serialized,
            'expires_at' => $expiresAt,
        ]);

        return true;
    }

    public function delete(string $key): bool
    {
        $this->ensureCacheTable();
        $this->db->delete($this->table, 'cache_key = :key', ['key' => $key]);
        return true;
    }

    public function clear(): bool
    {
        $this->ensureCacheTable();
        $this->db->statement(sprintf('DELETE FROM %s', $this->table));
        return true;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function remember(string $key, int $ttlSeconds, callable $callback): mixed
    {
        $cached = $this->get($key);
        if ($cached !== null) {
            return $cached;
        }

        $value = $callback();
        $this->set($key, $value, $ttlSeconds);
        return $value;
    }

    private function calculateExpiration(int|DateInterval|null $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if (is_int($ttl)) {
            return time() + $ttl;
        }

        $now = new DateTimeImmutable();
        return $now->add($ttl)->getTimestamp();
    }
}
