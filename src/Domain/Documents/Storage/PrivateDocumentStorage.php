<?php

declare(strict_types=1);

namespace Coleza\Domain\Documents\Storage;

use Coleza\Domain\Documents\DocumentType;
use InvalidArgumentException;
use RuntimeException;

final class PrivateDocumentStorage implements DocumentStorageInterface
{
    private string $baseDirectory;

    public function __construct(?string $baseDirectory = null)
    {
        $baseDir = $baseDirectory ?? (dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'documents');
        $this->baseDirectory = rtrim($baseDir, '/\\');
        
        if (!is_dir($this->baseDirectory)) {
            mkdir($this->baseDirectory, 0700, true);
        }
    }

    public function putDocument(string $path, string $content): bool
    {
        $fullPath = $this->resolveFullPath($path);
        $directory = dirname($fullPath);

        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $bytesWritten = file_put_contents($fullPath, $content, LOCK_EX);

        return $bytesWritten !== false;
    }

    public function getDocument(string $path): ?string
    {
        $fullPath = $this->resolveFullPath($path);
        if (!file_exists($fullPath)) {
            return null;
        }

        $content = file_get_contents($fullPath);
        return $content !== false ? $content : null;
    }

    public function exists(string $path): bool
    {
        $fullPath = $this->resolveFullPath($path);
        return file_exists($fullPath);
    }

    public function delete(string $path): bool
    {
        $fullPath = $this->resolveFullPath($path);
        if (!file_exists($fullPath)) {
            return false;
        }

        return unlink($fullPath);
    }

    public function resolveStoragePath(
        string|int $tenantId,
        DocumentType $type,
        string $documentNumber,
        int $version,
        string $extension = 'pdf'
    ): string {
        $safeNumber = preg_replace('/[^A-Za-z0-9_-]/', '', $documentNumber) ?? 'doc';
        $year = (int) date('Y');
        if (preg_match('/-(\d{4})-/', $documentNumber, $m)) {
            $year = (int) $m[1];
        }

        $sanitizedTenant = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $tenantId) ?? '1';
        $ext = ltrim($extension, '.');

        return sprintf(
            '%s/%s/%d/%s_v%d.%s',
            $sanitizedTenant,
            $type->value,
            $year,
            $safeNumber,
            $version,
            $ext
        );
    }

    public function verifyChecksum(string $path, string $expectedSha256): bool
    {
        $content = $this->getDocument($path);
        if ($content === null) {
            return false;
        }

        $actualHash = hash('sha256', $content);
        return hash_equals($expectedSha256, $actualHash);
    }

    private function resolveFullPath(string $relativePath): string
    {
        // Guard against path traversal
        if (str_contains($relativePath, '..')) {
            throw new InvalidArgumentException('Directory traversal detected in document storage path.');
        }

        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($relativePath, '/\\'));
        return $this->baseDirectory . DIRECTORY_SEPARATOR . $normalized;
    }
}
