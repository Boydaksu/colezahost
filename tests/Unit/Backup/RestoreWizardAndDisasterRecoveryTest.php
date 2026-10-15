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
        $journal=\Coleza\Domain\Backup\RestoreJournal::path($this->tempFreshAppDir);$state=\Coleza\Domain\Backup\RestoreJournal::read($journal);
        $previous=$state['previous_directory']??null;if(is_string($previous)&&str_starts_with($previous,$this->tempFreshAppDir.'.previous_')){$this->removeDirectory($previous);}
        foreach([$journal,$journal.'.lock']as$path){if(is_file($path)){unlink($path);}}
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

    public static function keyRestoreCases(): array { return [['correct'], ['wrong'], ['missing'], ['corrupt'], ['unbound'], ['encrypted-wrong'], ['queue-corrupt'], ['queue-legacy'], ['staging-owner'], ['staging-legacy'], ['staging-diagnostics']]; }
    #[\PHPUnit\Framework\Attributes\DataProvider('keyRestoreCases')]
    public function testBoundRestoreRequiresTheApplicationKeyAndDeepCipherVerification(string $case): void
    {
        $source = new Connection(new PDO('sqlite::memory:'));
        (new \Coleza\Domain\Installer\DatabaseSetupService())->initializeApplicationSchema($source);
        $key = \Coleza\Tests\Support\SecretFixtureKey::encryptor();
        (new \Coleza\Domain\Vault\VaultService($source, $key))->setSecret('provider:restore', 'token', 'restore-synthetic-secret');
        (new \Coleza\Foundation\Queue\DatabaseQueue($source, new \Coleza\Domain\Vault\QueuePayloadStore($source, $key)))->push(new \Coleza\Tests\Support\QueueSecretJob(['password' => 'restore-synthetic-queue-secret']));
        $staging=new \Coleza\Domain\Migration\Staging\DatabaseStagingRepository($source,$key);
        $staged=\Coleza\Domain\Migration\Staging\StagingRecord::createNew('restore','csv','client','7',['password'=>'synthetic-restore-staging']);$staged->markTransformed('client',['token'=>'synthetic-restore-canonical']);$staging->save($staged);
        (new \Coleza\Domain\Vault\KeyLifecycleService($source, $key))->initialize(true);
        if ($case === 'corrupt') { $source->statement("UPDATE vault_secrets SET encrypted_value = 'enc:v1:broken'"); }
        if ($case === 'unbound') { $source->statement('DELETE FROM system_settings WHERE setting_key = ?', [\Coleza\Domain\Vault\KeyBinding::SETTING]); }
        if ($case === 'queue-corrupt') { $source->statement("UPDATE jobs SET queue = 'tampered'"); }
        if ($case === 'queue-legacy') { $source->statement('UPDATE jobs SET payload = ?', [serialize(new \Coleza\Tests\Support\QueueSecretJob())]); }
        if ($case==='staging-owner') { $source->statement("UPDATE migration_staging_records SET source_system='tampered'"); }
        if ($case==='staging-legacy') { $source->statement("UPDATE migration_staging_records SET raw_payload_json='{}'"); }
        if ($case==='staging-diagnostics') { $source->statement('UPDATE migration_staging_records SET quarantine_reason=?',['Echoed synthetic-restore-staging']); }
        $file = $this->tempWorkDir . '/verified-asset.txt'; file_put_contents($file, 'asset');
        $adapter = new LocalBackupStorageAdapter($this->tempStorageDir);
        $archiveEncrypted = $case === 'encrypted-wrong';
        $backup = (new EnterpriseBackupService($this->tempWorkDir, $source, encryptionService: new BackupEncryptionService('synthetic-archive-passphrase')))->createBackup(BackupScope::FULL, $adapter, [$file], $archiveEncrypted);
        $oldKey = getenv('APP_KEY'); $oldFile = getenv('APP_KEY_FILE');
        try {
            putenv('APP_KEY'); putenv('APP_KEY_FILE');
            $restoreKey = match ($case) { 'wrong', 'encrypted-wrong' => \Coleza\Domain\Vault\ApplicationKey::encryptor(\Coleza\Domain\Vault\ApplicationKey::generate()), 'missing' => null, default => $key };
            $inventoryPath=$this->tempWorkDir.'/authoritative-empty-tombstones.json';file_put_contents($inventoryPath,'[]');
            $privacy=new \Coleza\Domain\Privacy\Integrity\PrivacyIntegrityVerifier($this->freshHostingDb,['tombstone'=>\Coleza\Tests\Support\PrivacyFixtureKeys::tombstone(),'email'=>\Coleza\Tests\Support\PrivacyFixtureKeys::email()],new \Coleza\Domain\Privacy\Tombstone\FileTombstoneStore($inventoryPath,\Coleza\Tests\Support\PrivacyFixtureKeys::tombstone()));
            $result = (new RestoreWizardService($this->tempWorkDir, applicationEncryptor: $restoreKey,privacyVerifier:$privacy))->executeRestore($backup['manifest']->getBackupId(), $adapter, $this->freshHostingDb, $this->tempFreshAppDir, $archiveEncrypted ? 'synthetic-archive-passphrase' : null);
            self::assertSame($case === 'correct', $result->isSuccess(), $result->getErrorMessage() ?? '');
            if ($case === 'correct') {
                self::assertSame('restore-synthetic-secret', (new \Coleza\Domain\Vault\VaultService($this->freshHostingDb, $key))->getSecret('provider:restore', 'token'));
                self::assertSame('restore-synthetic-queue-secret', (new \Coleza\Foundation\Queue\DatabaseQueue($this->freshHostingDb, new \Coleza\Domain\Vault\QueuePayloadStore($this->freshHostingDb, $key)))->pop()->getJob()->data['password']);
                self::assertSame(['password'=>'synthetic-restore-staging'],(new \Coleza\Domain\Migration\Staging\DatabaseStagingRepository($this->freshHostingDb,$key))->find($staged->getId())->getRawPayload());
                self::assertFileExists($this->tempFreshAppDir . '/verified-asset.txt');
            } else {
                self::assertFileDoesNotExist($this->tempFreshAppDir . '/verified-asset.txt');
                self::assertGreaterThan(0, $result->getDatabaseStatementsExecuted()); self::assertSame(0, $result->getFilesRestored());
            }
        } finally {
            $oldKey === false ? putenv('APP_KEY') : putenv('APP_KEY=' . $oldKey);
            $oldFile === false ? putenv('APP_KEY_FILE') : putenv('APP_KEY_FILE=' . $oldFile);
        }
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
