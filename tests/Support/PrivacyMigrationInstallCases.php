<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;

trait PrivacyMigrationInstallCases
{
    public static function requiredPrivacyMigrationTables(): array
    {
        return array_map(static fn (string $table): array => [$table], [
            'privacy_consents', 'privacy_tombstones', 'privacy_requests', 'privacy_redaction_audits',
            'privacy_retention_policies', 'privacy_legal_holds', 'privacy_erasure_logs', 'privacy_processing_restrictions',
            'migration_staging_records', 'migration_checkpoints', 'migration_holds', 'suppressed_notifications', 'adopted_provider_identities',
        ]);
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('requiredPrivacyMigrationTables')]
    public function testInstallerPreparesPrivacyAndMigrationTable(string $table): void
    {
        (new \Coleza\Domain\Installer\DatabaseSetupService())->initializeApplicationSchema($this->db);
        $this->db->select("SELECT * FROM {$table}");
        self::assertTrue(true);
    }
    public function testConsentFirstAllowsSignedTombstoneAndRetainsSignatureOnReinstall(): void
    {
        $consent = new \Coleza\Domain\Privacy\PrivacyConsentService($this->db);
        $consent->grantConsent(7, 'marketing_email');
        $signed = new \Coleza\Domain\Privacy\Tombstone\PrivacyTombstoneService($this->db, secretKey: 'test-secret', salt: 'test-salt');
        $signed->recordTombstone(7, 'subject@example.test', reason: 'Silme talebi');
        self::assertTrue($signed->getTombstoneByUserId(7)->verifyIntegrity('test-secret'));
        self::assertTrue($signed->isEmailTombstoned('subject@example.test'));
        $before = $this->db->select('SELECT * FROM privacy_tombstones');
        (new \Coleza\Domain\Installer\DatabaseSetupService())->initializeApplicationSchema($this->db);
        self::assertSame($before, $this->db->select('SELECT * FROM privacy_tombstones'));
        self::assertTrue($consent->hasConsent(7, 'marketing_email'));
    }
    public function testSignedSchemaFirstAllowsLegacyConsentApiWithoutInventingSignature(): void
    {
        $signed = new \Coleza\Domain\Privacy\Tombstone\PrivacyTombstoneService($this->db, secretKey: 'test-secret');
        $signed->ensureTables();
        $consent = new \Coleza\Domain\Privacy\PrivacyConsentService($this->db);
        $consent->recordTombstone(8, reason: 'Legacy deletion');
        self::assertTrue($consent->isTombstoned(8));
        self::assertTrue($signed->isTombstoned(8));
        self::assertFalse($signed->getTombstoneByUserId(8)->verifyIntegrity('test-secret'));
    }
    public function testOldConsentTombstoneIsPreservedAsUnverified(): void
    {
        $id = \Coleza\Foundation\Database\PdoSchema::autoIncrement($this->db->getPdo());
        $this->db->statement("CREATE TABLE privacy_tombstones (id {$id}, user_id INT NOT NULL UNIQUE, erasure_type VARCHAR(50) NOT NULL, reason VARCHAR(255) NULL, tombstoned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
        $this->db->statement("INSERT INTO privacy_tombstones (user_id, erasure_type, reason, tombstoned_at) VALUES (9, 'ANONYMIZE', NULL, '2020-01-02 03:04:05')");
        $before = $this->db->select('SELECT * FROM privacy_tombstones')[0];
        $setup = new \Coleza\Domain\Installer\DatabaseSetupService();
        $setup->initializeApplicationSchema($this->db);
        $after = $this->db->select('SELECT * FROM privacy_tombstones')[0];
        self::assertSame($before, array_intersect_key($after, $before));
        self::assertSame('', $after['integrity_signature']);
        $signed = new \Coleza\Domain\Privacy\Tombstone\PrivacyTombstoneService($this->db);
        self::assertTrue($signed->isTombstoned(9));
        self::assertFalse($signed->getTombstoneByUserId(9)->verifyIntegrity());
        $setup->initializeApplicationSchema($this->db);
        self::assertSame($after, $this->db->select('SELECT * FROM privacy_tombstones')[0]);
    }
    public function testMigrationCheckpointAndCustomizedRetentionSurviveReinstall(): void
    {
        $setup = new \Coleza\Domain\Installer\DatabaseSetupService();
        $setup->initializeApplicationSchema($this->db);
        $checkpoints = new \Coleza\Domain\Migration\Execution\MigrationCheckpointRepository($this->db);
        $checkpoints->save(new \Coleza\Domain\Migration\Execution\MigrationCheckpoint('fixture-batch', 'resume', '42', 42, 100, metadata: ['note' => 'Geçiş durumu']));
        $this->db->statement("UPDATE privacy_retention_policies SET retention_days = 9999 WHERE policy_key = 'support_tickets'");
        $before = $this->db->select('SELECT * FROM migration_checkpoints');
        $policies = $this->db->select('SELECT * FROM privacy_retention_policies ORDER BY id');
        $setup->initializeApplicationSchema($this->db);
        self::assertSame($before, $this->db->select('SELECT * FROM migration_checkpoints'));
        self::assertSame($policies, $this->db->select('SELECT * FROM privacy_retention_policies ORDER BY id'));
        self::assertSame('42', $checkpoints->find('fixture-batch')->getLastProcessedId());
    }
    public function testOriginalSignedSchemaKeepsConstraintsAndRecords(): void
    {
        $id = \Coleza\Foundation\Database\PdoSchema::autoIncrement($this->db->getPdo());
        $this->db->statement("CREATE TABLE privacy_tombstones (id {$id}, user_id INT NOT NULL UNIQUE, user_email_hash VARCHAR(64) NOT NULL, erasure_type VARCHAR(32) NOT NULL, reason TEXT NOT NULL, erasure_checksum VARCHAR(64) NOT NULL, metadata TEXT NULL, tombstoned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, integrity_signature VARCHAR(64) NOT NULL)");
        $signed = new \Coleza\Domain\Privacy\Tombstone\PrivacyTombstoneService($this->db, secretKey: 'original-key');
        $signed->recordTombstone(10, 'old@example.test', reason: 'Original signed record');
        $before = $this->db->select('SELECT * FROM privacy_tombstones');
        (new \Coleza\Domain\Installer\DatabaseSetupService())->initializeApplicationSchema($this->db);
        self::assertSame($before, $this->db->select('SELECT * FROM privacy_tombstones'));
        (new \Coleza\Domain\Privacy\PrivacyConsentService($this->db))->recordTombstone(11);
        self::assertTrue($signed->getTombstoneByUserId(10)->verifyIntegrity('original-key'));
        self::assertFalse($signed->getTombstoneByUserId(11)->verifyIntegrity('original-key'));
    }
}
