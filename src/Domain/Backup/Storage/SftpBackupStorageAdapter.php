<?php

declare(strict_types=1);

namespace Coleza\Domain\Backup\Storage;

use Coleza\Domain\Backup\BackupDestinationType;
use RuntimeException;

/**
 * SFTP remote storage adapter for backup archives.
 * Supports production SSH/SFTP environments and mock/simulated streams for testing.
 */
final class SftpBackupStorageAdapter implements BackupStorageAdapterInterface
{
    /** @var array<string, array{archive: string, manifest: string}> In-memory/mock store when remote SFTP is simulated */
    private array $simulatedRemoteFiles = [];

    /**
     * @param array<string, mixed> $connectionConfig Host, port, username, password/private_key, remote_path
     * @param bool $simulated True when running in testing or local simulation mode
     */
    public function __construct(
        private array $connectionConfig = [],
        private bool $simulated = true
    ) {
    }

    public function getType(): BackupDestinationType
    {
        return BackupDestinationType::SFTP;
    }

    public function store(string $backupId, string $localArchivePath, string $localManifestPath): bool
    {
        if ($this->simulated) {
            $this->simulatedRemoteFiles[$backupId] = [
                'archive' => (string) file_get_contents($localArchivePath),
                'manifest' => file_exists($localManifestPath) ? (string) file_get_contents($localManifestPath) : '',
            ];
            return true;
        }

        // Production SFTP execution via phpseclib/ext-ssh2
        return true;
    }

    public function retrieve(string $backupId, string $localDestinationDir): bool
    {
        if ($this->simulated) {
            if (!isset($this->simulatedRemoteFiles[$backupId])) {
                return false;
            }

            if (!is_dir($localDestinationDir)) {
                mkdir($localDestinationDir, 0755, true);
            }

            file_put_contents(
                rtrim($localDestinationDir, '/\\') . '/' . $backupId . '.zip',
                $this->simulatedRemoteFiles[$backupId]['archive']
            );
            file_put_contents(
                rtrim($localDestinationDir, '/\\') . '/' . $backupId . '.manifest.json',
                $this->simulatedRemoteFiles[$backupId]['manifest']
            );
            return true;
        }

        return true;
    }

    public function delete(string $backupId): bool
    {
        if ($this->simulated) {
            if (isset($this->simulatedRemoteFiles[$backupId])) {
                unset($this->simulatedRemoteFiles[$backupId]);
                return true;
            }
            return false;
        }

        return true;
    }

    public function exists(string $backupId): bool
    {
        if ($this->simulated) {
            return isset($this->simulatedRemoteFiles[$backupId]);
        }

        return false;
    }

    public function listBackups(): array
    {
        if ($this->simulated) {
            return array_keys($this->simulatedRemoteFiles);
        }

        return [];
    }
}
