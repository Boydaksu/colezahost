<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class AbuseSchemaTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\AbuseSchemaCases;
    protected function setUp(): void { parent::setUp(); $this->root->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false); }
    public function testFreshAbuseTablesUseTransactionalStorage(): void
    {
        (new \Coleza\Domain\Abuse\AbuseCaseService($this->db))->ensureTables();
        foreach (['abuse_cases', 'abuse_case_messages'] as $table) {
            self::assertSame('InnoDB', $this->db->selectOne('SELECT ENGINE FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table])['ENGINE']);
        }
    }
}
