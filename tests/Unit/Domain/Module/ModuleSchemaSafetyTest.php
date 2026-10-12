<?php
declare(strict_types=1);
namespace Coleza\Tests\Unit\Domain\Module;
final class ModuleSchemaSafetyTest extends \PHPUnit\Framework\TestCase
{
    use \Coleza\Tests\Support\ModuleSchemaSafetyCases;
    private \Coleza\Foundation\Database\Connection $db;
    protected function setUp(): void { $this->db = new \Coleza\Foundation\Database\Connection(new \PDO('sqlite::memory:')); }
}
