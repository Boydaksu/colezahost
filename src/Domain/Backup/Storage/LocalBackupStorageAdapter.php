<?php

declare(strict_types=1);

namespace Coleza\Domain\Backup\Storage;

use Coleza\Domain\Backup\BackupDestinationType;
use RuntimeException;

/**
 * Local filesystem storage adapter for backup archives.
 */
final class LocalBackupStorageAdapter implements BackupStorageAdapterInterface
{
    public function __construct(
        private string $storageDirectory
    ) {
        if (!is_dir($storageDirectory)) {
            mkdir($storageDirectory, 0755, true);
        }
    }

    public function getType(): BackupDestinationType
    {
        return BackupDestinationType::LOCAL;
    }

    public function store(string $backupId, string $localArchivePath, string $localManifestPath): bool
    {
        $targetArchive = $this->getArchivePath($backupId);
        $targetManifest = $this->getManifestPath($backupId);

        if (!copy($localArchivePath, $targetArchive)) {
            throw new RuntimeException("Failed to copy archive to local storage: {$targetArchive}");
        }

        if (file_exists($localManifestPath)) {
            if (!copy($localManifestPath, $targetManifest)) {
                throw new RuntimeException("Failed to copy manifest to local storage: {$targetManifest}");
            }
        }

        return true;
    }

    public function retrieve(string $backupId, string $localDestinationDir): bool
    {
        $sourceArchive = $this->getArchivePath($backupId);
        $sourceManifest = $this->getManifestPath($backupId);

        if (!file_exists($sourceArchive)) {
            return false;
        }

        if (!is_dir($localDestinationDir)) {
            mkdir($localDestinationDir, 0755, true);
        }

        copy($sourceArchive, rtrim($localDestinationDir, '/\\') . '/' . basename($sourceArchive));
        if (file_exists($sourceManifest)) {
            copy($sourceManifest, rtrim($localDestinationDir, '/\\') . '/' . basename($sourceManifest));
        }

        return true;
    }

    public function delete(string $backupId): bool
    {
        $archive = $this->getArchivePath($backupId);
        $manifest = $this->getManifestPath($backupId);

        $deleted = false;
        if (file_exists($archive)) {
            unlink($archive);
            $deleted = true;
        }
        if (file_exists($manifest)) {
            unlink($manifest);
        }

        return $deleted;
    }

    public function exists(string $backupId): bool
    {
        return file_exists($this->getArchivePath($backupId));
    }

    public function listBackups(): array
    {
        $files = glob(rtrim($this->storageDirectory, '/\\') . '/*.manifest.json');
        if (!$files) {
            return [];
        }

        $ids = [];
        foreach ($files as $file) {
            $base = basename($file, '.manifest.json');
            $ids[] = $base;
        }

        return $ids;
    }

    private function getArchivePath(string $backupId): string
    {
        return rtrim($this->storageDirectory, '/\\') . '/' . $backupId . '.zip';
    }

    private function getManifestPath(string $backupId): string
    {
        return rtrim($this->storageDirectory, '/\\') . '/' . $backupId . '.manifest.json';
    }
}
