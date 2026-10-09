<?php

declare(strict_types=1);

namespace Coleza\Domain\Backup\Storage;

use Coleza\Domain\Backup\BackupDestinationType;

/**
 * Storage adapter interface for backup archives.
 */
interface BackupStorageAdapterInterface
{
    public function getType(): BackupDestinationType;

    public function store(string $backupId, string $localArchivePath, string $localManifestPath): bool;

    public function retrieve(string $backupId, string $localDestinationDir): bool;

    public function delete(string $backupId): bool;

    public function exists(string $backupId): bool;

    /**
     * @return array<int, string> List of backupIds present in storage
     */
    public function listBackups(): array;
}
