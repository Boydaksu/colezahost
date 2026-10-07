<?php

declare(strict_types=1);

namespace Coleza\Foundation\Cache;

use DateInterval;

interface CacheInterface
{
    /**
     * Fetches a value from the cache.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Persists data in the cache, uniquely referenced by a key with an optional expiration TTL.
     */
    public function set(string $key, mixed $value, int|DateInterval|null $ttl = null): bool;

    /**
     * Delete an item from the cache by its unique key.
     */
    public function delete(string $key): bool;

    /**
     * Wipes clean the entire cache's keys.
     */
    public function clear(): bool;

    /**
     * Determines whether an item is present in the cache.
     */
    public function has(string $key): bool;

    /**
     * Get an item from cache, or execute the given callback and store the result.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function remember(string $key, int $ttlSeconds, callable $callback): mixed;
}
