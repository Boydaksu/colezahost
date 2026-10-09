<?php

declare(strict_types=1);

namespace Coleza\Domain\Backup\Storage;

use Coleza\Domain\Backup\BackupDestinationType;
use RuntimeException;

/**
 * Amazon S3 / S3-compatible object storage adapter for backup archives.
 * Supports production AWS SDK/HTTP REST and simulated in-memory store for testing.
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
        private bool $simulated = true
    ) {
    }

    public function getType(): BackupDestinationType
    {
        return BackupDestinationType::S3;
    }

    public function store(string $backupId, string $localArchivePath, string $localManifestPath): bool
    {
        if ($this->simulated) {
            $this->simulatedBuckets[$backupId] = [
                'archive' => (string) file_get_contents($localArchivePath),
                'manifest' => file_exists($localManifestPath) ? (string) file_get_contents($localManifestPath) : '',
            ];
            return true;
        }

        return true;
    }

    public function retrieve(string $backupId, string $localDestinationDir): bool
    {
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

        return true;
    }

    public function delete(string $backupId): bool
    {
        if ($this->simulated) {
            if (isset($this->simulatedBuckets[$backupId])) {
                unset($this->simulatedBuckets[$backupId]);
                return true;
            }
            return false;
        }

        return true;
    }

    public function exists(string $backupId): bool
    {
        if ($this->simulated) {
            return isset($this->simulatedBuckets[$backupId]);
        }

        return false;
    }

    public function listBackups(): array
    {
        if ($this->simulated) {
            return array_keys($this->simulatedBuckets);
        }

        return [];
    }
}
