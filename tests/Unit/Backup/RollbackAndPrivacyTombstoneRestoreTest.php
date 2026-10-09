<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Backup;

use Coleza\Domain\Backup\BackupScope;
use Coleza\Domain\Backup\EnterpriseBackupService;
use Coleza\Domain\Backup\RestoreWizardService;
use Coleza\Domain\Backup\Storage\LocalBackupStorageAdapter;
use Coleza\Domain\Privacy\Tombstone\BackupRestoreReconciliationService;
use Coleza\Domain\Privacy\Tombstone\FileTombstoneStore;
use Coleza\Domain\Privacy\Tombstone\PrivacyTombstone;
use Coleza\Domain\Privacy\Tombstone\PrivacyTombstoneService;
use Coleza\Domain\Updater\RollbackManager;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class RollbackAndPrivacyTombstoneRestoreTest extends TestCase
{
    private string $tempWorkDir;
    private string $tempStorageDir;
    private string $tempAppDir;
    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempWorkDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rollback_work_' . uniqid();
        $this->tempStorageDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rollback_store_' . uniqid();
        $this->tempAppDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rollback_app_' . uniqid();

        mkdir($this->tempWorkDir, 0777, true);
        mkdir($this->tempStorageDir, 0777, true);
        mkdir($this->tempAppDir, 0777, true);

        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        // Create base users table
        $this->db->statement(
            "CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                name TEXT,
                email TEXT,
                status TEXT
            )"
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempWorkDir);
        $this->removeDirectory($this->tempStorageDir);
        $this->removeDirectory($this->tempAppDir);

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

    public function testRestoreReappliesPrivacyTombstonePreventingPiiResurrection(): void
    {
        // 1. Time T0: User 42 ("John Doe", "john@example.com") is in database
        $this->db->statement("INSERT INTO users (id, name, email, status) VALUES (42, 'John Doe', 'john@example.com', 'active')");
        $this->db->statement("INSERT INTO users (id, name, email, status) VALUES (99, 'Alice Smith', 'alice@example.com', 'active')");

        // Take pre-update backup at T0 where John Doe still exists
        $localAdapter = new LocalBackupStorageAdapter($this->tempStorageDir);
        $backupService = new EnterpriseBackupService(
            workingDirectory: $this->tempWorkDir,
            db: $this->db,
            appVersion: '1.0.0'
        );

        $appConfigFile = $this->tempWorkDir . '/version.txt';
        file_put_contents($appConfigFile, '1.0.0');

        $backupResult = $backupService->createBackup(
            scope: BackupScope::FULL,
            destination: $localAdapter,
            filePaths: [$appConfigFile]
        );
        $backupId = $backupResult['manifest']->getBackupId();

        // 2. Time T1: John Doe submits GDPR Article 17 erasure request.
        // A Privacy Tombstone is recorded and persisted out-of-band.
        $tombstoneFile = $this->tempWorkDir . '/tombstones.json';
        $outOfBandStore = new FileTombstoneStore($tombstoneFile);
        $tombstoneService = new PrivacyTombstoneService(
            db: $this->db,
            persistentStore: $outOfBandStore,
            salt: 'secret_salt_123'
        );
        $tombstoneService->ensureTables();

        $tombstone = $tombstoneService->recordTombstone(
            userId: 42,
            email: 'john@example.com',
            erasureType: 'ERASURE_FULL',
            reason: 'Right to be forgotten request'
        );

        // Erase John Doe in operational database
        $this->db->statement(
            "UPDATE users SET name = '[Anonymized User #42]', email = 'erased_42@privacy.local', status = 'erased' WHERE id = 42"
        );

        // Verify out-of-band store holds tombstone
        $this->assertTrue($outOfBandStore->hasUserId(42));

        // 3. Time T2: Disaster strikes or failed update triggers rollback.
        // The database is restored from backup T0 (which contains John Doe's plaintext PII!).
        $reconciliationService = new BackupRestoreReconciliationService(
            db: $this->db,
            tombstoneService: $tombstoneService
        );

        $restoreWizard = new RestoreWizardService(
            temporaryExtractDir: $this->tempWorkDir,
            reconciliationService: $reconciliationService
        );

        $rollbackManager = new RollbackManager($restoreWizard, $localAdapter);

        // Execute Rollback
        $restoreResult = $rollbackManager->executeRollback(
            preUpdateBackupId: $backupId,
            targetDb: $this->db,
            targetAppDir: $this->tempAppDir
        );

        $this->assertTrue($restoreResult->isSuccess());

        // 4. Verify Constitution Rule: Privacy Tombstone must re-scrub resurrected user 42
        $reconcileReport = $restoreResult->getReconciliationReport();
        $this->assertNotNull($reconcileReport);
        $this->assertTrue($reconcileReport->isClean());
        $this->assertSame(1, $reconcileReport->getResurrectedUsersDetected());
        $this->assertSame(1, $reconcileReport->getResurrectedUsersReScrubbed());
        $this->assertContains(42, $reconcileReport->getScrubbedUserIds());

        // Inspect database record for user 42: must NOT contain resurrected plaintext PII
        $userRow = $this->db->selectOne("SELECT * FROM users WHERE id = 42");
        $this->assertNotNull($userRow);
        $this->assertSame('[Anonymized User #42]', $userRow['name']);
        $this->assertStringStartsWith('erased_42_', (string) $userRow['email']);
        $this->assertSame('erased', $userRow['status']);

        // Non-erased user (Alice Smith) must remain fully intact
        $aliceRow = $this->db->selectOne("SELECT * FROM users WHERE id = 99");
        $this->assertNotNull($aliceRow);
        $this->assertSame('Alice Smith', $aliceRow['name']);
        $this->assertSame('alice@example.com', $aliceRow['email']);
    }
}
