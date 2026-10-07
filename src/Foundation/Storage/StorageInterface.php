<?php

declare(strict_types=1);

namespace Coleza\Foundation\Storage;

interface StorageInterface
{
    /**
     * Store contents at given path.
     */
    public function put(string $path, string $contents): bool;

    /**
     * Retrieve contents of the file at given path.
     */
    public function get(string $path): ?string;

    /**
     * Determine if a file exists at given path.
     */
    public function exists(string $path): bool;

    /**
     * Delete the file at given path.
     */
    public function delete(string $path): bool;

    /**
     * Get the file size in bytes.
     */
    public function size(string $path): int;

    /**
     * Get the MIME type of the file.
     */
    public function mimeType(string $path): ?string;

    /**
     * Get the absolute filesystem path (only for local driver).
     */
    public function path(string $path): string;
}
