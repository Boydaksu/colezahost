<?php
declare(strict_types=1);
namespace Coleza\Domain\Backup;

use Coleza\Domain\Backup\Storage\{BackupPackage,LocalBackupStorageAdapter};
use Coleza\Foundation\Database\Connection;
use RuntimeException;
use Throwable;

/** Pre-update policy over the shared consistent snapshot and verified delivery pipeline. */
final class PreUpdateBackupService
{
    public function __construct(
        private string $backupStorageDir,
        private ?Connection $db = null,
        private string $appVersion = '1.0.0'
    ) {}

    public function getBackupStorageDir(): string {return $this->backupStorageDir;}

    public function assertContext(Connection $db, string $sourceVersion): void
    {
        if ($this->db === null || $this->db->getPdo() !== $db->getPdo() || $this->appVersion !== $sourceVersion) {
            throw new RuntimeException('Mandatory pre-update backup database/source version differs from updater context.');
        }
    }

    /**
     * @param array<int|string,string> $extraFilePaths Required files/directories, optionally keyed by relative alias
     * @return array{manifest:BackupManifest,archive_path:string,verification:BackupVerificationReport}
     */
    public function createVerifiedPreUpdateBackup(string $targetVersion, array $extraFilePaths = []): array
    {
        if ($this->db === null) {throw new RuntimeException('Pre-update backup requires a database connection.');}
        $storage = new LocalBackupStorageAdapter($this->backupStorageDir);
        $result = (new EnterpriseBackupService($this->backupStorageDir, $this->db, $this->appVersion))->createBackup(
            BackupScope::FULL, $storage, $extraFilePaths, preUpdateTargetVersion: $targetVersion
        );
        $manifest = $result['manifest'];
        $verification = $this->verifyBackup($manifest->getBackupId());
        if (!$verification->isValid()) {
            throw new RuntimeException('Pre-update backup verification failed: '.implode(', ', $verification->getErrors()));
        }
        return ['manifest'=>$manifest, 'archive_path'=>$verification->getArchivePath(), 'verification'=>$verification];
    }

    /** Verify an independent copy, including every contained checksum, before declaring recovery evidence. */
    public function verifyBackup(string $backupId): BackupVerificationReport
    {
        $archivePath = '';
        $archiveExists = false;
        $checksumMatches = false;
        $contentsReadable = false;
        $errors = [];
        $details = [];
        $stage = null;
        try {
            BackupPackage::id($backupId);
            $archivePath = rtrim($this->backupStorageDir, '/\\').'/'.$backupId.'.zip';
            $archiveExists = is_file($archivePath) && !is_link($archivePath);
            $storage = new LocalBackupStorageAdapter($this->backupStorageDir);
            $stage = rtrim($this->backupStorageDir, '/\\').'/preupdate_verify_'.bin2hex(random_bytes(12));
            if (!mkdir($stage, 0700)) {throw new RuntimeException('Cannot create private pre-update verification staging.');}
            if (!$storage->retrieve($backupId, $stage)) {throw new RuntimeException('Pre-update archive or manifest is missing.');}
            $copy = $stage.'/'.$backupId.'.zip';
            $data = BackupPackage::verify($backupId, $copy, $stage.'/'.$backupId.'.manifest.json');
            $checksumMatches = true;
            $manifest = BackupManifest::fromArray($data);
            $inventory = $manifest->getMetadata()['database'] ?? null;
            if ($manifest->getType() !== 'pre_update' || !is_array($inventory) || ($inventory['format'] ?? null) !== 2
                || !in_array($inventory['driver'] ?? null, ['mysql','sqlite'], true)
                || !is_array($inventory['tables'] ?? null) || !is_string($inventory['sql_mode'] ?? null)
                || ($inventory['driver'] === 'mysql' && ($inventory['time_zone'] ?? null) !== '+00:00')) {
                throw new RuntimeException('Pre-update backup requires a complete format-2 database inventory.');
            }
            $entries = (new BackupArchive())->extract($copy, $manifest, $stage.'/contents');
            // Parse the complete dump, so truncated or comment-only nonempty inventories cannot pass.
            $creates = 0;
            $inserts = 0;
            foreach ($inventory['tables'] as $table) {
                if (!is_array($table) || !is_int($table['rows'] ?? null) || $table['rows'] < 0) {
                    throw new RuntimeException('Invalid pre-update database table inventory.');
                }
                $inserts += $table['rows'];
            }
            $actualInserts = 0;
            foreach (SqlStatementReader::file($stage.'/contents/database.sql', $inventory['driver'] === 'mysql' && !str_contains($inventory['sql_mode'], 'NO_BACKSLASH_ESCAPES')) as $sql) {
                if (preg_match('/^CREATE TABLE\s/i', $sql)) {$creates++;}
                if (preg_match('/^INSERT INTO `/i', $sql)) {$actualInserts++;}
            }
            if ($creates !== count($inventory['tables']) || $actualInserts !== $inserts) {
                throw new RuntimeException('Pre-update dump does not match its table/row inventory.');
            }
            $contentsReadable = true;
            $details = ['file_size'=>filesize($copy), 'num_files'=>count($entries), 'database_format'=>2];
        } catch (Throwable $error) {
            $errors[] = $error->getMessage();
        } finally {
            if ($stage !== null) {BackupFileTree::removeOwned($stage);}
        }
        return new BackupVerificationReport($backupId, $archivePath, $errors === [], $archiveExists, $checksumMatches, $contentsReadable, $errors, $details);
    }
}
