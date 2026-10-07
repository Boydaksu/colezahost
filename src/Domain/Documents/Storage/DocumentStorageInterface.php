<?php

declare(strict_types=1);

namespace Coleza\Domain\Documents\Storage;

use Coleza\Domain\Documents\DocumentType;

interface DocumentStorageInterface
{
    public function putDocument(string $path, string $content): bool;

    public function getDocument(string $path): ?string;

    public function exists(string $path): bool;

    public function delete(string $path): bool;

    public function resolveStoragePath(
        string|int $tenantId,
        DocumentType $type,
        string $documentNumber,
        int $version,
        string $extension = 'pdf'
    ): string;

    public function verifyChecksum(string $path, string $expectedSha256): bool;
}
