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
        $journal=\Coleza\Domain\Backup\RestoreJournal::path($this->tempAppDir);$state=\Coleza\Domain\Backup\RestoreJournal::read($journal);
        $previous=$state['previous_directory']??null;if(is_string($previous)&&str_starts_with($previous,$this->tempAppDir.'.previous_')){$this->removeDirectory($previous);}
        foreach([$journal,$journal.'.lock']as$path){if(is_file($path)){unlink($path);}}
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
        $outOfBandStore = new FileTombstoneStore($tombstoneFile, secretKey: \Coleza\Tests\Support\PrivacyFixtureKeys::tombstone());
        $tombstoneService = new PrivacyTombstoneService(
            db: $this->db,
            persistentStore: $outOfBandStore,
            secretKey: \Coleza\Tests\Support\PrivacyFixtureKeys::tombstone(),
            salt: \Coleza\Tests\Support\PrivacyFixtureKeys::email()
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

        $this->assertTrue($restoreResult->isSuccess(),$restoreResult->getErrorMessage()??'');

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

    public function testWrongPrivacyKeyBlocksSuccessAndFileCopyAfterSqlImport(): void
    {
        $this->db->insert('users', ['id' => 7, 'name' => 'Synthetic subject', 'email' => 'subject@example.test', 'status' => 'active']);
        $keys = ['tombstone' => \Coleza\Tests\Support\PrivacyFixtureKeys::tombstone(), 'email' => \Coleza\Tests\Support\PrivacyFixtureKeys::email()];
        (new PrivacyTombstoneService($this->db, secretKey: $keys['tombstone'], salt: $keys['email']))->recordTombstone(7, 'subject@example.test');
        $adapter = new LocalBackupStorageAdapter($this->tempStorageDir);
        $file = $this->tempWorkDir . '/privacy-gate.txt'; file_put_contents($file, 'new preserved version');
        $backup = (new EnterpriseBackupService($this->tempWorkDir, $this->db, '1.0.0'))->createBackup(BackupScope::FULL, $adapter, filePaths: [$file]);
        self::assertArrayHasKey('manifest', $backup);
        $destination = $this->tempAppDir . '/privacy-gate.txt'; file_put_contents($destination, 'existing version');
        $target = new Connection(new PDO('sqlite::memory:'));
        $keys['tombstone'] = \Coleza\Tests\Support\PrivacyFixtureKeys::redaction();
        $emptyInventory=$this->tempWorkDir.'/authoritative-empty.json';file_put_contents($emptyInventory,'[]');
        $verifier = new \Coleza\Domain\Privacy\Integrity\PrivacyIntegrityVerifier($this->db, $keys,new FileTombstoneStore($emptyInventory,$keys['tombstone']));
        $result = (new RestoreWizardService($this->tempWorkDir . '/privacy_wrong_restore', privacyVerifier: $verifier))->executeRestore($backup['manifest']->getBackupId(), $adapter, $target, $this->tempAppDir);
        self::assertFalse($result->isSuccess()); self::assertSame(0, $result->getFilesRestored());
        self::assertStringContainsString('Privacy key', $result->getErrorMessage());
        self::assertSame('existing version', file_get_contents($destination));
        // SQL import is deliberately observable; this gate does not claim to roll back the whole dump.
        self::assertSame('active', $target->selectOne('SELECT status FROM users WHERE id=7')['status']);
    }
    public function testInjectedReconcilerIsReboundToActualRestoreTarget(): void
    {
        $this->db->insert('users', ['id' => 7, 'name' => 'Synthetic source', 'email' => 'subject@example.test', 'status' => 'active']);
        $keys = ['tombstone' => \Coleza\Tests\Support\PrivacyFixtureKeys::tombstone(), 'email' => \Coleza\Tests\Support\PrivacyFixtureKeys::email()];
        $file = $this->tempWorkDir . '/target-tombstones.json'; $store = new FileTombstoneStore($file, $keys['tombstone']);
        $writer = new PrivacyTombstoneService($this->db, $store, secretKey: $keys['tombstone'], salt: $keys['email']); $writer->recordTombstone(7, 'subject@example.test', reason: "DPO's \\ archive \"quoted\"; approved", metadata: ['count' => 1.0, 'path' => 'C:\\synthetic']);
        $sourcePrivacy = $this->db->select('SELECT * FROM privacy_tombstones');
        $source = $this->db->select('SELECT * FROM users');
        $adapter = new LocalBackupStorageAdapter($this->tempStorageDir);
        $backup = (new EnterpriseBackupService($this->tempWorkDir, $this->db, '1.0.0'))->createBackup(BackupScope::FULL, $adapter);
        self::assertArrayHasKey('manifest', $backup); $target = new Connection(new PDO('sqlite::memory:'));
        $reconciler = new BackupRestoreReconciliationService($this->db, $writer);
        $result = (new RestoreWizardService($this->tempWorkDir . '/privacy_target_restore', reconciliationService: $reconciler))->executeRestore($backup['manifest']->getBackupId(), $adapter, $target, $this->tempAppDir);
        self::assertTrue($result->isSuccess(), $result->getErrorMessage() ?? ''); self::assertSame([7], $result->getReconciliationReport()->getScrubbedUserIds());
        self::assertSame('erased', $target->selectOne('SELECT status FROM users WHERE id=7')['status']);
        self::assertSame($source, $this->db->select('SELECT * FROM users'));
        self::assertSame($sourcePrivacy, $target->select('SELECT * FROM privacy_tombstones'));
    }
}
