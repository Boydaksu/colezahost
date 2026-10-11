<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class PrivacyMigrationInstallTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\PrivacyMigrationInstallCases;
    protected function setUp(): void
    {
        parent::setUp();
        $this->root->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
    }
    public function testInstalledPrivacyAndCheckpointWritesRemainRollbackable(): void
    {
        (new \Coleza\Domain\Installer\DatabaseSetupService())->initializeApplicationSchema($this->db);
        $consent = new \Coleza\Domain\Privacy\PrivacyConsentService($this->db);
        $signed = new \Coleza\Domain\Privacy\Tombstone\PrivacyTombstoneService($this->db, secretKey: 'fixture-secret');
        $checkpoints = new \Coleza\Domain\Migration\Execution\MigrationCheckpointRepository($this->db);
        $this->root->beginTransaction();
        try {
            $consent->grantConsent(7, 'transaction-fixture');
            $signed->recordTombstone(7, 'transaction@example.test');
            $checkpoints->save(new \Coleza\Domain\Migration\Execution\MigrationCheckpoint('transaction-fixture', 'resume'));
            self::assertTrue($this->root->inTransaction());
            self::assertTrue($consent->hasConsent(7, 'transaction-fixture'));
        } finally {
            if ($this->root->inTransaction()) { $this->root->rollBack(); }
        }
        self::assertSame([], $this->db->select('SELECT * FROM privacy_consents'));
        self::assertSame([], $this->db->select('SELECT * FROM privacy_tombstones'));
        self::assertSame([], $this->db->select('SELECT * FROM migration_checkpoints'));
    }
    public function testMissingTransactionalSchemaIsRejectedWithoutImplicitCommit(): void
    {
        $this->db->statement('CREATE TABLE fixture_marker (id INT PRIMARY KEY)');
        $this->root->beginTransaction();
        $this->db->statement('INSERT INTO fixture_marker VALUES (1)');
        $caught = null;
        try {
            (new \Coleza\Domain\Privacy\Tombstone\PrivacyTombstoneService($this->db))->ensureTables();
        } catch (\LogicException $error) {
            $caught = $error;
        } finally {
            $active = $this->root->inTransaction();
            if ($active) { $this->root->rollBack(); }
        }
        self::assertNotNull($caught);
        self::assertTrue($active);
        self::assertSame([], $this->db->select('SELECT * FROM fixture_marker'));
        self::assertNull($this->db->selectOne("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'privacy_tombstones'"));
    }
}
