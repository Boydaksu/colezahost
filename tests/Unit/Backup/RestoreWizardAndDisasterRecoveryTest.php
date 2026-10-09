<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Backup;

use Coleza\Domain\Backup\BackupDestinationType;
use Coleza\Domain\Backup\BackupEncryptionService;
use Coleza\Domain\Backup\BackupScope;
use Coleza\Domain\Backup\EnterpriseBackupService;
use Coleza\Domain\Backup\RestoreResult;
use Coleza\Domain\Backup\RestoreWizardService;
use Coleza\Domain\Backup\Storage\LocalBackupStorageAdapter;
use Coleza\Domain\Backup\Storage\S3BackupStorageAdapter;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class RestoreWizardAndDisasterRecoveryTest extends TestCase
{
    private string $tempWorkDir;
    private string $tempStorageDir;
    private string $tempFreshAppDir;
    private Connection $sourceDb;
    private Connection $freshHostingDb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempWorkDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'restore_work_' . uniqid();
        $this->tempStorageDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'restore_storage_' . uniqid();
        $this->tempFreshAppDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'restore_fresh_app_' . uniqid();

        mkdir($this->tempWorkDir, 0777, true);
        mkdir($this->tempStorageDir, 0777, true);
        mkdir($this->tempFreshAppDir, 0777, true);

        // 1. Source Database with critical operational data
        $srcPdo = new PDO('sqlite::memory:');
        $this->sourceDb = new Connection($srcPdo, 'sqlite');
        $this->sourceDb->statement("CREATE TABLE clients (id INTEGER PRIMARY KEY, email TEXT, company TEXT)");
        $this->sourceDb->statement("INSERT INTO clients (id, email, company) VALUES (1, 'ceo@megaenterprise.com', 'Mega Corp')");
        $this->sourceDb->statement("CREATE TABLE invoices (id INTEGER PRIMARY KEY, num TEXT, total REAL)");
        $this->sourceDb->statement("INSERT INTO invoices (id, num, total) VALUES (101, 'INV-2026-001', 999.50)");

        // 2. Fresh Target Hosting Database (completely blank / empty)
        $freshPdo = new PDO('sqlite::memory:');
        $this->freshHostingDb = new Connection($freshPdo, 'sqlite');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempWorkDir);
        $this->removeDirectory($this->tempStorageDir);
        $this->removeDirectory($this->tempFreshAppDir);

        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testEndToEndDisasterRecoveryOnFreshHosting(): void
    {
        // 1. Take Full Unencrypted Backup from Source system
        $backupService = new EnterpriseBackupService(
            workingDirectory: $this->tempWorkDir,
            db: $this->sourceDb,
            appVersion: '1.0.0'
        );

        $localAdapter = new LocalBackupStorageAdapter($this->tempStorageDir);

        $criticalConfigFile = $this->tempWorkDir . '/app_license.txt';
        file_put_contents($criticalConfigFile, 'DISASTER-RECOVERY-KEY-999');

        $backupResult = $backupService->createBackup(
            scope: BackupScope::FULL,
            destination: $localAdapter,
            filePaths: [$criticalConfigFile],
            encrypt: false
        );

        $backupId = $backupResult['manifest']->getBackupId();

        // 2. Disaster Recovery: Perform Restore onto Fresh Hosting Environment
        $restoreWizard = new RestoreWizardService($this->tempWorkDir);

        // Pre-restore inspection
        $inspect = $restoreWizard->inspectBackup($backupId, $localAdapter);
        $this->assertTrue($inspect['db_dump_found']);
        $this->assertFalse($inspect['is_encrypted']);
        $this->assertGreaterThan(0, $inspect['files_count']);

        // Execute restore
        $restoreResult = $restoreWizard->executeRestore(
            backupId: $backupId,
            sourceStorage: $localAdapter,
            targetDb: $this->freshHostingDb,
            targetAppDir: $this->tempFreshAppDir
        );

        $this->assertTrue($restoreResult->isSuccess(), 'Restore failed: ' . ($restoreResult->getErrorMessage() ?? 'unknown'));
        $this->assertGreaterThan(0, $restoreResult->getDatabaseStatementsExecuted());
        $this->assertSame(1, $restoreResult->getFilesRestored());
        $this->assertContains('clients', $restoreResult->getTablesRestored());
        $this->assertContains('invoices', $restoreResult->getTablesRestored());

        // 3. Verify Database content on fresh hosting
        $client = $this->freshHostingDb->selectOne('SELECT email, company FROM clients WHERE id = 1');
        $this->assertNotNull($client);
        $this->assertSame('ceo@megaenterprise.com', $client['email']);
        $this->assertSame('Mega Corp', $client['company']);

        $invoice = $this->freshHostingDb->selectOne('SELECT num, total FROM invoices WHERE id = 101');
        $this->assertNotNull($invoice);
        $this->assertSame('INV-2026-001', $invoice['num']);
        $this->assertEquals(999.50, (float) $invoice['total']);

        // 4. Verify preserved files on fresh hosting filesystem
        $this->assertFileExists($this->tempFreshAppDir . '/app_license.txt');
        $this->assertSame('DISASTER-RECOVERY-KEY-999', file_get_contents($this->tempFreshAppDir . '/app_license.txt'));
    }

    public function testRestoreEncryptedBackupFromS3ObjectStorage(): void
    {
        $passphrase = 'enterprise-disaster-vault-passphrase';
        $encryptor = new BackupEncryptionService($passphrase);

        $backupService = new EnterpriseBackupService(
            workingDirectory: $this->tempWorkDir,
            db: $this->sourceDb,
            appVersion: '1.0.0',
            encryptionService: $encryptor
        );

        $s3Adapter = new S3BackupStorageAdapter([], simulated: true);

        $backupResult = $backupService->createBackup(
            scope: BackupScope::FULL,
            destination: $s3Adapter,
            encrypt: true
        );

        $backupId = $backupResult['manifest']->getBackupId();
        $this->assertTrue($backupResult['encrypted']);

        // Restore using wizard
        $restoreWizard = new RestoreWizardService($this->tempWorkDir, $encryptor);

        // Inspection requires passphrase
        $inspect = $restoreWizard->inspectBackup($backupId, $s3Adapter, $passphrase);
        $this->assertTrue($inspect['is_encrypted']);
        $this->assertTrue($inspect['db_dump_found']);

        // Execute restore
        $restoreResult = $restoreWizard->executeRestore(
            backupId: $backupId,
            sourceStorage: $s3Adapter,
            targetDb: $this->freshHostingDb,
            targetAppDir: $this->tempFreshAppDir,
            decryptionPassphrase: $passphrase
        );

        $this->assertTrue($restoreResult->isSuccess());

        $client = $this->freshHostingDb->selectOne('SELECT email FROM clients WHERE id = 1');
        $this->assertSame('ceo@megaenterprise.com', $client['email']);
    }

    public function testRestoreRejectsCorruptedOrTamperedArchive(): void
    {
        $backupService = new EnterpriseBackupService(
            workingDirectory: $this->tempWorkDir,
            db: $this->sourceDb,
            appVersion: '1.0.0'
        );

        $localAdapter = new LocalBackupStorageAdapter($this->tempStorageDir);
        $backupResult = $backupService->createBackup(BackupScope::FULL, $localAdapter);
        $backupId = $backupResult['manifest']->getBackupId();

        // Corrupt archive on disk
        $archivePath = $this->tempStorageDir . '/' . $backupId . '.zip';
        file_put_contents($archivePath, 'tampered_malicious_archive_data');

        $restoreWizard = new RestoreWizardService($this->tempWorkDir);
        $restoreResult = $restoreWizard->executeRestore(
            backupId: $backupId,
            sourceStorage: $localAdapter,
            targetDb: $this->freshHostingDb,
            targetAppDir: $this->tempFreshAppDir
        );

        $this->assertFalse($restoreResult->isSuccess());
        $this->assertStringContainsString('Tamper detection failed', (string) $restoreResult->getErrorMessage());
    }
}
