<?php

declare(strict_types=1);

namespace Coleza\Domain\Backup;

use Coleza\Domain\Backup\Storage\BackupStorageAdapterInterface;
use Coleza\Foundation\Database\Connection;
use RuntimeException;
use ZipArchive;

/**
 * Master Enterprise Backup Management Service.
 * Supports:
 * - Scopes: Full (DB + Files), DB-only, Files-only.
 * - Destinations: Local disk, SFTP remote, AWS S3.
 * - Optional authenticated AES-256-GCM encryption.
 * - Cryptographic manifest with SHA-256 integrity seal.
 * - Policy-driven automated retention and pruning.
 */
final class EnterpriseBackupService
{
    public function __construct(
        private string $workingDirectory,
        private ?Connection $db = null,
        private string $appVersion = '1.0.0',
        private ?BackupEncryptionService $encryptionService = null
    ) {
        if (!is_dir($workingDirectory)) {
            mkdir($workingDirectory, 0755, true);
        }
    }

    public function getWorkingDirectory(): string
    {
        return $this->workingDirectory;
    }

    /**
     * Creates an enterprise backup according to specified scope and options.
     *
     * @param BackupScope $scope
     * @param BackupStorageAdapterInterface $destination
     * @param array<int, string> $filePaths Specific files/directories to include if scope is FULL or FILES
     * @param bool $encrypt Whether to apply AES-256-GCM encryption
     * @param ?BackupRetentionPolicy $retentionPolicy Optional retention policy to apply immediately
     * @return array{manifest: BackupManifest, destination_type: string, encrypted: bool}
     */
    public function createBackup(
        BackupScope $scope,
        BackupStorageAdapterInterface $destination,
        array $filePaths = [],
        bool $encrypt = false,
        ?BackupRetentionPolicy $retentionPolicy = null
    ): array {
        $timestamp = date('Ymd_His');
        $backupId = sprintf('backup_%s_%s_%s', $scope->value, $timestamp, bin2hex(random_bytes(4)));
        $tempArchive = rtrim($this->workingDirectory, '/\\') . '/' . $backupId . '.zip';
        $tempManifest = rtrim($this->workingDirectory, '/\\') . '/' . $backupId . '.manifest.json';

        $zip = new ZipArchive();
        $res = $zip->open($tempArchive, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($res !== true) {
            throw new RuntimeException("Failed to create backup ZIP archive: {$tempArchive} (code {$res})");
        }

        $containedFiles = [];

        // 1. Process Database Dump if requested
        if (($scope === BackupScope::FULL || $scope === BackupScope::DATABASE) && $this->db !== null) {
            $sqlDump = $this->dumpDatabase($this->db);
            $zip->addFromString('database.sql', $sqlDump);
            $containedFiles['database.sql'] = hash('sha256', $sqlDump);
        }

        // 2. Process Files if requested
        if ($scope === BackupScope::FULL || $scope === BackupScope::FILES) {
            foreach ($filePaths as $path) {
                if (file_exists($path) && is_file($path)) {
                    $content = (string) file_get_contents($path);
                    $relName = 'files/' . basename($path);
                    $zip->addFromString($relName, $content);
                    $containedFiles[$relName] = hash('sha256', $content);
                }
            }
        }

        $zip->close();

        // 3. Optional AES-256-GCM Encryption
        $isEncrypted = false;
        if ($encrypt) {
            if ($this->encryptionService === null) {
                throw new RuntimeException('Encryption requested but BackupEncryptionService is not configured.');
            }

            $encryptedArchive = $tempArchive . '.enc';
            $this->encryptionService->encryptFile($tempArchive, $encryptedArchive);
            unlink($tempArchive);
            rename($encryptedArchive, $tempArchive);
            $isEncrypted = true;
        }

        // 4. Compute Checksum and Manifest
        $fileSize = (int) filesize($tempArchive);
        $checksum = (string) hash_file('sha256', $tempArchive);

        $manifest = new BackupManifest(
            backupId: $backupId,
            type: $scope->value,
            sourceAppVersion: $this->appVersion,
            fileSizeBytes: $fileSize,
            sha256Checksum: $checksum,
            createdAt: date('c'),
            containedFiles: $containedFiles,
            metadata: [
                'encrypted' => $isEncrypted,
                'encryption_algorithm' => $isEncrypted ? 'aes-256-gcm' : 'none',
                'destination' => $destination->getType()->value,
            ]
        );

        file_put_contents($tempManifest, json_encode($manifest->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // 5. Transfer to Destination Adapter
        $destination->store($backupId, $tempArchive, $tempManifest);

        // Clean local temp files if destination is not local or if configured
        if ($destination->getType() !== BackupDestinationType::LOCAL) {
            @unlink($tempArchive);
            @unlink($tempManifest);
        }

        // 6. Apply Retention Policy if provided
        if ($retentionPolicy !== null) {
            $manifests = [$backupId => $manifest];
            $retentionPolicy->apply($destination, $manifests);
        }

        return [
            'manifest' => $manifest,
            'destination_type' => $destination->getType()->value,
            'encrypted' => $isEncrypted,
        ];
    }

    private function dumpDatabase(Connection $db): string
    {
        $driver = $db->getDriverName();
        $dump = "-- Coleza Host Database Dump\n";
        $dump .= "-- Generated: " . date('c') . "\n\n";

        if ($driver === 'sqlite') {
            $tables = $db->select("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
            foreach ($tables as $t) {
                $tableName = (string) $t['name'];
                $tableSql = (string) $t['sql'];

                $dump .= "DROP TABLE IF EXISTS `{$tableName}`;\n";
                $dump .= $tableSql . ";\n";

                $rows = $db->select("SELECT * FROM `{$tableName}`");
                foreach ($rows as $row) {
                    $keys = array_keys($row);
                    $escapedValues = array_map(function ($val) {
                        if ($val === null) {
                            return 'NULL';
                        }
                        return "'" . addslashes((string) $val) . "'";
                    }, array_values($row));

                    $dump .= sprintf(
                        "INSERT INTO `%s` (`%s`) VALUES (%s);\n",
                        $tableName,
                        implode('`, `', $keys),
                        implode(', ', $escapedValues)
                    );
                }
                $dump .= "\n";
            }
        }

        return $dump;
    }
}
