<?php

declare(strict_types=1);

namespace Coleza\Foundation\Cache;

use DateInterval;
use DateTimeImmutable;

final class FileCache implements CacheInterface
{
    private string $directory;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/\\');
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0755, true);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $path = $this->getFilePath($key);
        if (!file_exists($path)) {
            return $default;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return $default;
        }

        $data = @unserialize($raw);
        if (!is_array($data) || !array_key_exists('expires_at', $data)) {
            $this->delete($key);
            return $default;
        }

        if ($data['expires_at'] !== null && $data['expires_at'] < time()) {
            $this->delete($key);
            return $default;
        }

        return $data['value'];
    }

    public function set(string $key, mixed $value, int|DateInterval|null $ttl = null): bool
    {
        $path = $this->getFilePath($key);
        $expiresAt = $this->calculateExpiration($ttl);

        $payload = serialize([
            'expires_at' => $expiresAt,
            'value' => $value,
        ]);

        return file_put_contents($path, $payload, LOCK_EX) !== false;
    }

    public function delete(string $key): bool
    {
        $path = $this->getFilePath($key);
        if (file_exists($path)) {
            return @unlink($path);
        }
        return true;
    }

    public function clear(): bool
    {
        $files = glob($this->directory . '/*');
        if ($files === false) {
            return true;
        }

        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
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

    private function getFilePath(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . sha1($key) . '.cache';
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
