<?php

declare(strict_types=1);

namespace Coleza\Domain\Support\Attachments;

use InvalidArgumentException;

final class PrivateAttachmentStorage implements AttachmentStorageInterface
{
    private string $baseDirectory;

    public function __construct(?string $baseDirectory = null)
    {
        $base = $baseDirectory ?? (dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'attachments');
        $this->baseDirectory = rtrim($base, '/\\');

        if (!is_dir($this->baseDirectory)) {
            mkdir($this->baseDirectory, 0700, true);
        }
    }

    public function put(string $storageKey, string $content): bool
    {
        $fullPath = $this->resolveFullPath($storageKey);
        $directory = dirname($fullPath);

        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $bytes = file_put_contents($fullPath, $content, LOCK_EX);

        return $bytes !== false;
    }

    public function get(string $storageKey): ?string
    {
        $fullPath = $this->resolveFullPath($storageKey);
        if (!file_exists($fullPath)) {
            return null;
        }

        $content = file_get_contents($fullPath);

        return $content !== false ? $content : null;
    }

    public function exists(string $storageKey): bool
    {
        $fullPath = $this->resolveFullPath($storageKey);

        return file_exists($fullPath);
    }

    public function delete(string $storageKey): bool
    {
        $fullPath = $this->resolveFullPath($storageKey);
        if (!file_exists($fullPath)) {
            return false;
        }

        return unlink($fullPath);
    }

    public function getBaseDirectory(): string
    {
        return $this->baseDirectory;
    }

    private function resolveFullPath(string $storageKey): string
    {
        if (str_contains($storageKey, '..') || str_contains($storageKey, "\0")) {
            throw new InvalidArgumentException('Directory traversal or null bytes detected in storage key.');
        }

        $cleanKey = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $storageKey), DIRECTORY_SEPARATOR);

        return $this->baseDirectory . DIRECTORY_SEPARATOR . $cleanKey;
    }
}
