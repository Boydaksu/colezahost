<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;

final class LegacyIdentitySchemaTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\LegacyIdentityUpgradeCases;

    public function testCoreSchemaInitializationCannotCommitAnApplicationTransaction(): void
    {
        $this->root->beginTransaction();
        try {
            (new \Coleza\Domain\Installer\DatabaseSetupService())->initializeCoreSchema($this->db);
            self::fail('DDL in an application transaction must be rejected.');
        } catch (\LogicException $error) {
            self::assertTrue($this->root->inTransaction());
        } finally {
            if ($this->root->inTransaction()) { $this->root->rollBack(); }
        }
        self::assertSame([], $this->db->select('SHOW TABLES'));
    }
}
