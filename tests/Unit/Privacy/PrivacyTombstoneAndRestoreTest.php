<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Privacy;

use Coleza\Domain\Privacy\Erasure\PrivacyErasureService;
use Coleza\Domain\Privacy\Tombstone\BackupRestoreReconciliationService;
use Coleza\Domain\Privacy\Tombstone\FileTombstoneStore;
use Coleza\Domain\Privacy\Tombstone\PrivacyTombstone;
use Coleza\Domain\Privacy\Tombstone\PrivacyTombstoneService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class PrivacyTombstoneAndRestoreTest extends TestCase
{
    private Connection $db;
    private string $tempTombstoneFile;
    private FileTombstoneStore $fileStore;
    private PrivacyTombstoneService $tombstoneService;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->tempTombstoneFile = sys_get_temp_dir() . '/tombstones_' . bin2hex(random_bytes(6)) . '.json';
        $this->fileStore = new FileTombstoneStore($this->tempTombstoneFile);

        $this->tombstoneService = new PrivacyTombstoneService(
            db: $this->db,
            persistentStore: $this->fileStore,
            secretKey: 'test_tombstone_secret',
            salt: 'test_tombstone_salt'
        );
        $this->tombstoneService->ensureTables();

        $this->createUsersTable();
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempTombstoneFile)) {
            @unlink($this->tempTombstoneFile);
        }
    }

    private function createUsersTable(): void
    {
        $this->db->statement(
            'CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL UNIQUE,
                status VARCHAR(32) NOT NULL DEFAULT "active",
                phone VARCHAR(64) NULL,
                address TEXT NULL,
                tax_number VARCHAR(64) NULL,
                two_factor_secret VARCHAR(64) NULL,
                password_hash VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $this->db->statement(
            'CREATE TABLE IF NOT EXISTS privacy_consents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INT NOT NULL,
                purpose VARCHAR(64) NOT NULL,
                is_granted TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )'
        );
    }

    public function testRecordAndRetrievePrivacyTombstone(): void
    {
        $userId = 101;
        $email = 'subject101@example.org';

        $tombstone = $this->tombstoneService->recordTombstone(
            userId: $userId,
            email: $email,
            erasureType: 'ANONYMIZE',
            reason: 'GDPR Right to be Forgotten',
            erasureChecksum: 'abc123checksum',
            metadata: ['executed_by' => 1]
        );

        $this->assertSame($userId, $tombstone->getUserId());
        $this->assertSame('ANONYMIZE', $tombstone->getErasureType());
        $this->assertSame('GDPR Right to be Forgotten', $tombstone->getReason());
        $this->assertSame('abc123checksum', $tombstone->getErasureChecksum());
        $this->assertTrue($tombstone->matchesEmail($email, 'test_tombstone_salt'));
        $this->assertTrue($tombstone->verifyIntegrity('test_tombstone_secret'));

        // Verify presence via service
        $this->assertTrue($this->tombstoneService->isTombstoned($userId));
        $this->assertTrue($this->tombstoneService->isEmailTombstoned($email));
        $this->assertFalse($this->tombstoneService->isTombstoned(999));
        $this->assertFalse($this->tombstoneService->isEmailTombstoned('unrelated@example.org'));

        // Verify persisted in out-of-band file store
        $this->assertTrue($this->fileStore->hasUserId($userId));
        $loaded = $this->fileStore->findByUserId($userId);
        $this->assertNotNull($loaded);
        $this->assertSame($userId, $loaded->getUserId());
    }

    public function testTombstoneIntegrityVerificationDetectsTampering(): void
    {
        $tombstone = PrivacyTombstone::create(
            userId: 202,
            email: 'tamper@example.org',
            erasureType: 'DELETE',
            reason: 'KVKK erasure',
            erasureChecksum: 'hash123',
            secretKey: 'test_tombstone_secret',
            salt: 'test_tombstone_salt'
        );

        $this->assertTrue($tombstone->verifyIntegrity('test_tombstone_secret'));
        $this->assertFalse($tombstone->verifyIntegrity('wrong_secret'));

        // Tampered tombstone with altered checksum
        $tamperedData = $tombstone->toArray();
        $tamperedData['erasure_checksum'] = 'forged_checksum_xyz';
        $tampered = PrivacyTombstone::fromArray($tamperedData);

        $this->assertFalse($tampered->verifyIntegrity('test_tombstone_secret'));
    }

    public function testPrivacyErasureAutomaticallyRecordsTombstone(): void
    {
        $this->db->statement(
            "INSERT INTO users (id, name, email, status, phone, password_hash)
             VALUES (404, 'John Doe', 'john.doe@target.net', 'active', '+1555123456', 'hash_secret')"
        );

        $erasureService = new PrivacyErasureService(
            db: $this->db,
            legalHoldService: null,
            tombstoneService: $this->tombstoneService
        );
        $erasureService->ensureTables();

        $result = $erasureService->executeErasure(
            userId: 404,
            executedBy: 1,
            reason: 'User submitted right to erasure request under GDPR Art 17'
        );

        $this->assertTrue($result->isSuccess());

        // Verify tombstone was created automatically
        $this->assertTrue($this->tombstoneService->isTombstoned(404));
        $this->assertTrue($this->tombstoneService->isEmailTombstoned('john.doe@target.net'));

        $tombstone = $this->tombstoneService->getTombstoneByUserId(404);
        $this->assertNotNull($tombstone);
        $this->assertSame($result->getAuditChecksum(), $tombstone->getErasureChecksum());
        $this->assertTrue($this->fileStore->hasUserId(404));
    }

    /**
     * Golden Scenario G08:
     * Backup user -> Erase -> Restore old backup -> Apply tombstone -> PII must not resurrect.
     */
    public function testGoldenScenarioG08PrivacyRestoreReconciliation(): void
    {
        $userId = 777;
        $originalName = 'Alice Walker';
        $originalEmail = 'alice.walker@enterprise-client.com';
        $originalPhone = '+14155550199';

        // 1. Initial State: User Alice exists in production database with active consents
        $this->db->statement(
            "INSERT INTO users (id, name, email, status, phone, password_hash)
             VALUES (:id, :name, :email, 'active', :phone, 'bcrypt_hash_abc')",
            ['id' => $userId, 'name' => $originalName, 'email' => $originalEmail, 'phone' => $originalPhone]
        );
        $this->db->statement(
            "INSERT INTO privacy_consents (user_id, purpose, is_granted)
             VALUES (:uid, 'marketing_newsletter', 1)",
            ['uid' => $userId]
        );

        // --- SNAPSHOT T1 (Historical backup is created at this point) ---
        $backupUserSnapshot = $this->db->selectOne("SELECT * FROM users WHERE id = :id", ['id' => $userId]);
        $backupConsentSnapshot = $this->db->select("SELECT * FROM privacy_consents WHERE user_id = :uid", ['uid' => $userId]);

        // 2. Later (T2): User Alice invokes Right to Erasure
        $erasureService = new PrivacyErasureService(
            db: $this->db,
            legalHoldService: null,
            tombstoneService: $this->tombstoneService
        );
        $erasureService->ensureTables();

        $erasureResult = $erasureService->executeErasure(
            userId: $userId,
            executedBy: 1,
            reason: 'Formal GDPR right to erasure request'
        );
        $this->assertTrue($erasureResult->isSuccess());

        // Verify User is anonymized in DB and tombstone exists in persistent storage
        $erasedUser = $this->db->selectOne("SELECT * FROM users WHERE id = :id", ['id' => $userId]);
        $this->assertStringStartsWith('[Anonymized User #', (string) $erasedUser['name']);
        $this->assertTrue($this->fileStore->hasUserId($userId));

        // 3. Disaster occurs (T3): Old backup from T1 is restored onto database!
        // This simulates a full DB restore from T1: Alice's old personal data is revived in the DB!
        $this->db->statement(
            "UPDATE users
             SET name = :name, email = :email, status = 'active', phone = :phone, password_hash = 'bcrypt_hash_abc'
             WHERE id = :id",
            ['name' => $backupUserSnapshot['name'], 'email' => $backupUserSnapshot['email'], 'phone' => $backupUserSnapshot['phone'], 'id' => $userId]
        );
        $this->db->statement(
            "INSERT INTO privacy_consents (user_id, purpose, is_granted)
             VALUES (:uid, 'marketing_newsletter', 1)",
            ['uid' => $userId]
        );
        // Also simulate restored DB having lost the tombstone row from DB table (only persistent file store held it)
        $this->db->statement("DELETE FROM privacy_tombstones WHERE user_id = :id", ['id' => $userId]);

        // Verify that PII was momentarily resurrected by the raw backup restore
        $resurrectedUser = $this->db->selectOne("SELECT * FROM users WHERE id = :id", ['id' => $userId]);
        $this->assertSame($originalName, $resurrectedUser['name']);
        $this->assertSame($originalEmail, $resurrectedUser['email']);
        $this->assertSame('active', $resurrectedUser['status']);

        // 4. Constitution Rule Enforced:
        // "Backup restore reapplies Privacy Tombstones before production opens. PII must not resurrect."
        $reconciliationService = new BackupRestoreReconciliationService(
            db: $this->db,
            tombstoneService: $this->tombstoneService
        );

        $report = $reconciliationService->reconcileAfterBackupRestore();

        // 5. Assertions on Reconciliation Report
        $this->assertSame(1, $report->getTombstonesEvaluated());
        $this->assertSame(1, $report->getResurrectedUsersDetected());
        $this->assertSame(1, $report->getResurrectedUsersReScrubbed());
        $this->assertSame([$userId], $report->getScrubbedUserIds());
        $this->assertTrue($report->isClean());
        $this->assertTrue($report->isProductionReady());

        // 6. Verify resurrected PII was completely wiped and NOT resurrected!
        $sanitizedUser = $this->db->selectOne("SELECT * FROM users WHERE id = :id", ['id' => $userId]);
        $this->assertSame('[Anonymized User #777]', $sanitizedUser['name']);
        $this->assertStringStartsWith('erased_777_', (string) $sanitizedUser['email']);
        $this->assertSame('erased', $sanitizedUser['status']);
        $this->assertNull($sanitizedUser['phone']);
        $this->assertSame('ERASED', $sanitizedUser['password_hash']);

        // Consents restored from backup must be purged
        $activeConsents = $this->db->select("SELECT id FROM privacy_consents WHERE user_id = :uid", ['uid' => $userId]);
        $this->assertEmpty($activeConsents);

        // Tombstones table in DB must be repopulated from persistent store
        $this->assertTrue($this->tombstoneService->isTombstoned($userId));

        // 7. Verify Production Gate
        $reconciliationService->assertProductionReady($report);
    }

    public function testProductionGateBlocksIfReconciliationDirty(): void
    {
        $reconciliationService = new BackupRestoreReconciliationService(
            db: $this->db,
            tombstoneService: $this->tombstoneService
        );

        $dirtyReport = new \Coleza\Domain\Privacy\Tombstone\RestoreReconciliationReport(
            tombstonesEvaluated: 5,
            resurrectedUsersDetected: 2,
            resurrectedUsersReScrubbed: 1, // 1 failed to rescrub
            scrubbedUserIds: [10],
            isClean: false,
            reconciledAt: date('Y-m-d H:i:s'),
            auditDigest: 'dirty_digest'
        );

        $this->assertFalse($dirtyReport->isProductionReady());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Cannot open production: unscrubbed resurrected personal data detected.');

        $reconciliationService->assertProductionReady($dirtyReport);
    }

    public function testResurrectedUserDetectedByEmailHashEvenWithAlteredUserId(): void
    {
        // Setup tombstone for subject email
        $this->tombstoneService->recordTombstone(
            userId: 555,
            email: 'victim@identity.org',
            erasureType: 'ANONYMIZE',
            reason: 'Right to erasure'
        );

        // Simulate restore where user was imported under ID 888 with same email
        $this->db->statement(
            "INSERT INTO users (id, name, email, status, phone, password_hash)
             VALUES (888, 'Victim Name', 'victim@identity.org', 'active', '+1999999999', 'pwd')"
        );

        $reconciliationService = new BackupRestoreReconciliationService(
            db: $this->db,
            tombstoneService: $this->tombstoneService
        );

        $report = $reconciliationService->reconcileAfterBackupRestore();

        $this->assertSame(1, $report->getResurrectedUsersDetected());
        $this->assertSame(1, $report->getResurrectedUsersReScrubbed());
        $this->assertSame([888], $report->getScrubbedUserIds());

        $sanitized = $this->db->selectOne("SELECT * FROM users WHERE id = 888");
        $this->assertSame('[Anonymized User #888]', $sanitized['name']);
        $this->assertStringStartsWith('erased_888_', (string) $sanitized['email']);
    }

    public function testManifestExportAndImport(): void
    {
        $this->tombstoneService->recordTombstone(10, 'u10@manifest.org', 'ANONYMIZE', 'Test 1');
        $this->tombstoneService->recordTombstone(20, 'u20@manifest.org', 'DELETE', 'Test 2');

        $manifestJson = $this->fileStore->exportManifest();
        $this->assertJson($manifestJson);

        $newTempFile = sys_get_temp_dir() . '/tombstones_imported_' . bin2hex(random_bytes(6)) . '.json';
        $newStore = new FileTombstoneStore($newTempFile);

        $importedCount = $newStore->importManifest($manifestJson);
        $this->assertSame(2, $importedCount);
        $this->assertTrue($newStore->hasUserId(10));
        $this->assertTrue($newStore->hasUserId(20));

        if (file_exists($newTempFile)) {
            @unlink($newTempFile);
        }
    }
}
