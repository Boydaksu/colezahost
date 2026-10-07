<?php

declare(strict_types=1);

namespace Coleza\Foundation\Storage;

use InvalidArgumentException;

final class LocalStorage implements StorageInterface
{
    private string $root;

    public function __construct(string $rootPath)
    {
        $this->root = rtrim(str_replace('\\', '/', $rootPath), '/');
        if (!is_dir($this->root)) {
            mkdir($this->root, 0755, true);
        }
    }

    public function put(string $path, string $contents): bool
    {
        $fullPath = $this->resolvePath($path);
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return file_put_contents($fullPath, $contents, LOCK_EX) !== false;
    }

    public function get(string $path): ?string
    {
        $fullPath = $this->resolvePath($path);
        if (!file_exists($fullPath) || !is_readable($fullPath)) {
            return null;
        }

        $content = file_get_contents($fullPath);
        return $content !== false ? $content : null;
    }

    public function exists(string $path): bool
    {
        return file_exists($this->resolvePath($path));
    }

    public function delete(string $path): bool
    {
        $fullPath = $this->resolvePath($path);
        if (!file_exists($fullPath)) {
            return true;
        }

        return @unlink($fullPath);
    }

    public function size(string $path): int
    {
        $fullPath = $this->resolvePath($path);
        if (!file_exists($fullPath)) {
            return 0;
        }

        $size = filesize($fullPath);
        return $size !== false ? $size : 0;
    }

    public function mimeType(string $path): ?string
    {
        $fullPath = $this->resolvePath($path);
        if (!file_exists($fullPath)) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return 'application/octet-stream';
        }

        $mime = finfo_file($finfo, $fullPath);
        finfo_close($finfo);

        return $mime !== false ? $mime : 'application/octet-stream';
    }

    public function path(string $path): string
    {
        return $this->resolvePath($path);
    }

    /**
     * Resolve path and strictly prevent directory traversal vulnerabilities.
     */
    private function resolvePath(string $path): string
    {
        // Reject null bytes
        if (str_contains($path, "\0")) {
            throw new InvalidArgumentException('Null byte detected in storage path.');
        }

        $normalized = str_replace('\\', '/', $path);
        $segments = explode('/', $normalized);
        $safeSegments = [];

        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new InvalidArgumentException(sprintf('Path traversal attempt detected in path [%s].', $path));
            }
            $safeSegments[] = $segment;
        }

        $resolved = $this->root . '/' . implode('/', $safeSegments);

        // Final verification that path starts strictly with root directory
        if (!str_starts_with($resolved, $this->root)) {
            throw new InvalidArgumentException(sprintf('Path [%s] resolves outside the storage root.', $path));
        }

        return $resolved;
    }
}
