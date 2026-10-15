<?php

declare(strict_types=1);

namespace Coleza\Domain\Backup\Storage;

use Coleza\Domain\Backup\BackupDestinationType;
use RuntimeException;

/**
 * Amazon S3 / S3-compatible object storage adapter for backup archives.
 * Simulation requires explicit opt-in; unimplemented real transport fails closed.
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

        throw new RuntimeException('Real backup transport is not configured; operation was not performed.');
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

        throw new RuntimeException('Real backup transport is not configured; operation was not performed.');
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

        throw new RuntimeException('Real backup transport is not configured; operation was not performed.');
    }

    public function exists(string $backupId): bool
    {
        BackupPackage::id($backupId);
        if ($this->simulated) {
            return isset($this->simulatedBuckets[$backupId]);
        }

        throw new RuntimeException('Real backup transport is not configured; operation was not performed.');
    }

    public function listBackups(): array
    {
        if ($this->simulated) {
            return array_keys($this->simulatedBuckets);
        }

        throw new RuntimeException('Real backup transport is not configured; operation was not performed.');
    }
}
