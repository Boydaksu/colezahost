<?php

declare(strict_types=1);

namespace Coleza\Domain\Backup\Storage;

use Coleza\Domain\Backup\BackupDestinationType;

/**
 * SFTP remote storage adapter for backup archives.
 * Real transport uses authenticated, verified connections; simulation requires explicit opt-in.
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

        return $this->remote()->store($backupId, $localArchivePath, $localManifestPath);
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

        return $this->remote()->retrieve($backupId, $localDestinationDir);
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

        return $this->remote()->delete($backupId);
    }

    public function exists(string $backupId): bool
    {
        BackupPackage::id($backupId);
        if ($this->simulated) {
            return isset($this->simulatedRemoteFiles[$backupId]);
        }

        return $this->remote()->exists($backupId);
    }

    public function listBackups(): array
    {
        if ($this->simulated) {
            return array_keys($this->simulatedRemoteFiles);
        }

        return $this->remote()->listBackups();
    }
    private function remote(): RemoteBackupRepository
    {
        return new RemoteBackupRepository(new SftpCurlBackupTransport($this->connectionConfig));
    }
}
