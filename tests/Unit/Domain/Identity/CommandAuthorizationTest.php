<?php
declare(strict_types=1);
namespace Coleza\Tests\Unit\Domain\Identity;
final class CommandAuthorizationTest extends \PHPUnit\Framework\TestCase
{
    use \Coleza\Tests\Support\CommandAuthorizationCases;
    protected \Coleza\Foundation\Database\Connection $db;
    protected function setUp(): void{$this->db=new \Coleza\Foundation\Database\Connection(new \PDO('sqlite::memory:'),'sqlite');}
}
