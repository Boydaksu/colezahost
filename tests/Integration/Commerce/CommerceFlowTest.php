<?php
declare(strict_types=1);
namespace Coleza\Tests\Integration\Commerce;
final class CommerceFlowTest extends \PHPUnit\Framework\TestCase
{
    use \Coleza\Tests\Support\CommerceFlowCases;
    protected \Coleza\Foundation\Database\Connection $db;
    protected function setUp(): void {$this->db=new \Coleza\Foundation\Database\Connection(new \PDO('sqlite::memory:'),'sqlite');}
}
