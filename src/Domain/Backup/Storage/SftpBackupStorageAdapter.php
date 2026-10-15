<?php

declare(strict_types=1);

namespace Coleza\Domain\Backup\Storage;

use Coleza\Domain\Backup\BackupDestinationType;
use RuntimeException;

/**
 * SFTP remote storage adapter for backup archives.
 * Simulation requires explicit opt-in; unimplemented real transport fails closed.
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
        private bool $simulated = false
    ) {
    }

    public function getType(): BackupDestinationType
    {
        return BackupDestinationType::SFTP;
    }

    public function store(string $backupId, string $localArchivePath, string $localManifestPath): bool
    {
        BackupPackage::id($backupId);
        if ($this->simulated) {
            BackupPackage::verify($backupId,$localArchivePath,$localManifestPath);
            $this->simulatedRemoteFiles[$backupId] = [
                'archive' => (string) file_get_contents($localArchivePath),
                'manifest' => file_exists($localManifestPath) ? (string) file_get_contents($localManifestPath) : '',
            ];
            return true;
        }

        throw new RuntimeException('Real SFTP transport is not configured; backup transfer was not performed.');
    }

    public function retrieve(string $backupId, string $localDestinationDir): bool
    {
        BackupPackage::id($backupId);
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

        throw new RuntimeException('Real backup transport is not configured; operation was not performed.');
    }

    public function delete(string $backupId): bool
    {
        BackupPackage::id($backupId);
        if ($this->simulated) {
            if (isset($this->simulatedRemoteFiles[$backupId])) {
                unset($this->simulatedRemoteFiles[$backupId]);
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
            return isset($this->simulatedRemoteFiles[$backupId]);
        }

        throw new RuntimeException('Real backup transport is not configured; operation was not performed.');
    }

    public function listBackups(): array
    {
        if ($this->simulated) {
            return array_keys($this->simulatedRemoteFiles);
        }

        throw new RuntimeException('Real backup transport is not configured; operation was not performed.');
    }
}
