<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class FraudSchemaTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\FraudSchemaCases;
    protected function setUp(): void { parent::setUp(); $this->root->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false); }
    public function testFreshTablesUseTransactionalStorage(): void
    {
        $this->install(); foreach ($this->tables() as $table) { self::assertSame('InnoDB', $this->db->selectOne('SELECT ENGINE FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table])['ENGINE']); }
    }
}
