<?php

declare(strict_types=1);

namespace Coleza\Foundation\Lock;

interface LockInterface
{
    /**
     * Attempt to acquire a lock on a resource for given TTL.
     */
    public function acquire(string $resource, int $ttlSeconds = 60, ?string $owner = null): bool;

    /**
     * Release the lock on a resource.
     */
    public function release(string $resource, ?string $owner = null): bool;

    /**
     * Check if a resource is currently locked.
     */
    public function isLocked(string $resource): bool;

    /**
     * Execute a callback under an exclusive lock, automatically releasing it afterwards.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function synchronized(string $resource, callable $callback, int $ttlSeconds = 60): mixed;
}
