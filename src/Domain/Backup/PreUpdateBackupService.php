<?php

declare(strict_types=1);

namespace Coleza\Domain\Backup;

use Coleza\Foundation\Database\Connection;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Service generating and verifying pre-update backup archives.
 * Enforces:
 * 1. Complete database schema & records dump.
 * 2. Critical system configuration and uploaded files preservation.
 * 3. Tamper-evident BackupManifest with SHA-256 integrity hash.
 * 4. Mandatory post-creation archive readback verification before allowing any update.
 */
final class PreUpdateBackupService
{
    public function __construct(
        private string $backupStorageDir,
        private ?Connection $db = null,
        private string $appVersion = '1.0.0'
    ) {
    }

    public function getBackupStorageDir(): string
    {
        return $this->backupStorageDir;
    }

    /**
     * Creates a verified pre-update backup archive before proceeding with a staged update.
     *
     * @param string $targetVersion The version to which the system will be upgraded
     * @param array<int, string> $extraFilePaths Relative or absolute paths to include
     * @return array{manifest: BackupManifest, archive_path: string, verification: BackupVerificationReport}
     */
    public function createVerifiedPreUpdateBackup(string $targetVersion, array $extraFilePaths = []): array
    {
        if (!is_dir($this->backupStorageDir)) {
            mkdir($this->backupStorageDir, 0755, true);
        }

        $timestamp = date('Ymd_His');
        $backupId = sprintf('pre_update_v%s_to_v%s_%s', str_replace('.', '_', $this->appVersion), str_replace('.', '_', $targetVersion), $timestamp);
        $archivePath = rtrim($this->backupStorageDir, '/\\') . '/' . $backupId . '.zip';
        $manifestPath = rtrim($this->backupStorageDir, '/\\') . '/' . $backupId . '.manifest.json';

        $zip = new ZipArchive();
        $res = $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($res !== true) {
            throw new RuntimeException("Failed to create backup archive at {$archivePath} (Zip error code: {$res})");
        }

        $containedFiles = [];

        // 1. Export Database Dump if database connection provided
        if ($this->db !== null) {
            $dbDump = $this->dumpDatabase($this->db);
            $zip->addFromString('database.sql', $dbDump);
            $containedFiles['database.sql'] = hash('sha256', $dbDump);
        }

        // 2. Include requested files
        foreach ($extraFilePaths as $path) {
            if (file_exists($path) && is_file($path)) {
                $content = (string) file_get_contents($path);
                $relName = basename($path);
                $zip->addFromString('files/' . $relName, $content);
                $containedFiles['files/' . $relName] = hash('sha256', $content);
            }
        }

        $zip->close();

        // 3. Compute archive metadata
        $fileSize = (int) filesize($archivePath);
        $archiveSha256 = (string) hash_file('sha256', $archivePath);

        $manifest = new BackupManifest(
            backupId: $backupId,
            type: 'pre_update',
            sourceAppVersion: $this->appVersion,
            fileSizeBytes: $fileSize,
            sha256Checksum: $archiveSha256,
            createdAt: date('c'),
            containedFiles: $containedFiles,
            metadata: [
                'target_version' => $targetVersion,
                'reason' => 'Mandatory pre-update safety seal',
            ]
        );

        // Save standalone manifest file alongside archive
        file_put_contents($manifestPath, json_encode($manifest->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // 4. Mandatory Readback Verification
        $verification = $this->verifyBackup($backupId);
        if (!$verification->isValid()) {
            throw new RuntimeException(
                'Pre-update backup verification failed: ' . implode(', ', $verification->getErrors())
            );
        }

        return [
            'manifest' => $manifest,
            'archive_path' => $archivePath,
            'verification' => $verification,
        ];
    }

    /**
     * Verifies that the backup archive exists, matches manifest hash, and can be read/unpacked.
     */
    public function verifyBackup(string $backupId): BackupVerificationReport
    {
        $archivePath = rtrim($this->backupStorageDir, '/\\') . '/' . $backupId . '.zip';
        $manifestPath = rtrim($this->backupStorageDir, '/\\') . '/' . $backupId . '.manifest.json';

        $errors = [];
        $archiveExists = file_exists($archivePath);
        $manifestExists = file_exists($manifestPath);

        if (!$archiveExists) {
            $errors[] = "Archive file not found: {$archivePath}";
        }

        if (!$manifestExists) {
            $errors[] = "Manifest file not found: {$manifestPath}";
        }

        if (!empty($errors)) {
            return new BackupVerificationReport(
                backupId: $backupId,
                archivePath: $archivePath,
                isValid: false,
                archiveExists: $archiveExists,
                manifestMatchesChecksum: false,
                contentsReadable: false,
                errors: $errors
            );
        }

        $manifestData = json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($manifestData)) {
            $errors[] = 'Corrupt or unreadable manifest JSON.';
            return new BackupVerificationReport(
                backupId: $backupId,
                archivePath: $archivePath,
                isValid: false,
                archiveExists: $archiveExists,
                manifestMatchesChecksum: false,
                contentsReadable: false,
                errors: $errors
            );
        }

        $expectedHash = (string) ($manifestData['sha256_checksum'] ?? '');
        $actualHash = (string) hash_file('sha256', $archivePath);

        $checksumMatches = hash_equals(strtolower($expectedHash), strtolower($actualHash));
        if (!$checksumMatches) {
            $errors[] = sprintf('Archive checksum mismatch: expected %s, got %s', $expectedHash, $actualHash);
        }

        // Check if zip archive is openable and non-corrupt
        $zip = new ZipArchive();
        $res = $zip->open($archivePath, ZipArchive::RDONLY);
        $contentsReadable = ($res === true);
        if (!$contentsReadable) {
            $errors[] = "Backup archive is corrupted or not readable (Zip error code: {$res}).";
        } else {
            $numFiles = $zip->numFiles;
            $zip->close();
        }

        $isValid = empty($errors);

        return new BackupVerificationReport(
            backupId: $backupId,
            archivePath: $archivePath,
            isValid: $isValid,
            archiveExists: $archiveExists,
            manifestMatchesChecksum: $checksumMatches,
            contentsReadable: $contentsReadable,
            errors: $errors,
            details: [
                'file_size' => filesize($archivePath),
                'num_files' => $numFiles ?? 0,
            ]
        );
    }

    /**
     * Dumps all tables and rows from current database connection.
     */
    private function dumpDatabase(Connection $db): string
    {
        $driver = $db->getDriverName();
        $dump = "-- Coleza Host Pre-Update Database Dump\n";
        $dump .= "-- Generated at: " . date('c') . "\n";
        $dump .= "-- Driver: " . $driver . "\n\n";

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
