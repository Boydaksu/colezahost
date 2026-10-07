<?php

declare(strict_types=1);

namespace Coleza\Foundation\Cache;

use DateInterval;
use DateTimeImmutable;

final class ArrayCache implements CacheInterface
{
    /** @var array<string, array{value: mixed, expires_at: ?int}> */
    private array $storage = [];

    public function get(string $key, mixed $default = null): mixed
    {
        if (!isset($this->storage[$key])) {
            return $default;
        }

        $entry = $this->storage[$key];
        if ($entry['expires_at'] !== null && $entry['expires_at'] < time()) {
            unset($this->storage[$key]);
            return $default;
        }

        return $entry['value'];
    }

    public function set(string $key, mixed $value, int|DateInterval|null $ttl = null): bool
    {
        $expiresAt = $this->calculateExpiration($ttl);
        $this->storage[$key] = [
            'value' => $value,
            'expires_at' => $expiresAt,
        ];

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->storage[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->storage = [];
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
