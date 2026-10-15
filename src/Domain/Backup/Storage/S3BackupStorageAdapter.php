<?php

declare(strict_types=1);

namespace Coleza\Domain\Backup\Storage;

use Coleza\Domain\Backup\BackupDestinationType;

/**
 * Amazon S3 / S3-compatible object storage adapter for backup archives.
 * Real transport uses authenticated, verified connections; simulation requires explicit opt-in.
 */
final class S3BackupStorageAdapter implements BackupStorageAdapterInterface
{
    /** @var array<string, array{archive: string, manifest: string}> */
    private array $simulatedBuckets = [];

    /**
     * @param array<string, mixed> $s3Config bucket, region, access_key, secret_key, endpoint
     * @param bool $simulated
     */
    public function __construct(
        private array $s3Config = [],
        private bool $simulated = false
    ) {
    }

    public function getType(): BackupDestinationType
    {
        return BackupDestinationType::S3;
    }

    public function store(string $backupId, string $localArchivePath, string $localManifestPath): bool
    {
        BackupPackage::id($backupId);
        if ($this->simulated) {
            BackupPackage::verify($backupId,$localArchivePath,$localManifestPath);
            $this->simulatedBuckets[$backupId] = [
                'archive' => (string) file_get_contents($localArchivePath),
                'manifest' => file_exists($localManifestPath) ? (string) file_get_contents($localManifestPath) : '',
            ];
            return true;
        }

        return $this->remote()->store($backupId, $localArchivePath, $localManifestPath);
    }

    public function retrieve(string $backupId, string $localDestinationDir): bool
    {
        BackupPackage::id($backupId);
        if ($this->simulated) {
            if (!isset($this->simulatedBuckets[$backupId])) {
                return false;
            }

            if (!is_dir($localDestinationDir)) {
                mkdir($localDestinationDir, 0755, true);
            }

            file_put_contents(
                rtrim($localDestinationDir, '/\\') . '/' . $backupId . '.zip',
                $this->simulatedBuckets[$backupId]['archive']
            );
            file_put_contents(
                rtrim($localDestinationDir, '/\\') . '/' . $backupId . '.manifest.json',
                $this->simulatedBuckets[$backupId]['manifest']
            );
            return true;
        }

        return $this->remote()->retrieve($backupId, $localDestinationDir);
    }

    public function delete(string $backupId): bool
    {
        BackupPackage::id($backupId);
        if ($this->simulated) {
            if (isset($this->simulatedBuckets[$backupId])) {
                unset($this->simulatedBuckets[$backupId]);
                return true;
            }
            return false;
        }

        return $this->remote()->delete($backupId);
    }

    public function exists(string $backupId): bool
    {
        BackupPackage::id($backupId);
        if ($this->simulated) {
            return isset($this->simulatedBuckets[$backupId]);
        }

        return $this->remote()->exists($backupId);
    }

    public function listBackups(): array
    {
        if ($this->simulated) {
            return array_keys($this->simulatedBuckets);
        }

        return $this->remote()->listBackups();
    }
    private function remote(): RemoteBackupRepository
    {
        return new RemoteBackupRepository(new S3CurlBackupTransport($this->s3Config));
    }
}
