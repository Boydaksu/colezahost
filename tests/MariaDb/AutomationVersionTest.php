<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class AutomationVersionTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\AutomationVersionCases;
    protected function setUp(): void
    {
        parent::setUp();
        $this->root->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
    }
    public function testParallelPublishingAllocatesFourDifferentVersions(): void
    {
        $this->versionInstall();
        $results = $this->runParallel('automationVersion', 0);
        $versions = array_column($results, 'version'); sort($versions);
        self::assertSame([1, 2, 3, 4], $versions);
        self::assertCount(4, (new \Coleza\Domain\Automation\Versioning\DatabaseRuleVersionManager($this->db, \Coleza\Tests\Support\SecretFixtureKey::encryptor()))->getVersions('parallel_rule'));
    }
    public function testPublishingAfterOldSnapshotUsesCommittedLatestVersion(): void
    {
        $this->versionInstall();
        $manager = new \Coleza\Domain\Automation\Versioning\DatabaseRuleVersionManager($this->db, \Coleza\Tests\Support\SecretFixtureKey::encryptor());
        $manager->recordVersion($this->rule(), 'actor', 'First');
        $pdo = new \PDO((string) getenv('COLEZA_TEST_MARIADB_DSN'), getenv('COLEZA_TEST_MARIADB_USER'), getenv('COLEZA_TEST_MARIADB_PASSWORD'),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false]);
        $pdo->exec('USE `' . $this->database . '`');
        $other = new \Coleza\Domain\Automation\Versioning\DatabaseRuleVersionManager(new \Coleza\Foundation\Database\Connection($pdo), \Coleza\Tests\Support\SecretFixtureKey::encryptor());
        self::assertSame($manager->getVersion('versioned_rule', 1)->toArray(), $other->getVersion('versioned_rule', 1)->toArray());
        $this->db->beginTransaction();
        try {
            $this->db->select('SELECT * FROM automation_rule_versions');
            $other->recordVersion($this->rule(2), 'other', 'Second');
            self::assertSame(3, $manager->recordVersion($this->rule(3), 'actor', 'Third')->getVersionNumber());
        } finally { $this->db->rollBack(); }
        self::assertSame(2, $other->getLatestVersion('versioned_rule')->getVersionNumber());
    }
}
