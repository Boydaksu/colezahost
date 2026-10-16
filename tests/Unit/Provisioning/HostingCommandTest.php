<?php
declare(strict_types=1);
namespace Coleza\Tests\Unit\Provisioning;
final class HostingCommandTest extends \PHPUnit\Framework\TestCase
{
    use \Coleza\Tests\Support\HostingCommandCases;
    private \Coleza\Foundation\Database\Connection $db;
    protected function setUp():void{$this->db=new \Coleza\Foundation\Database\Connection(new \PDO("sqlite::memory:"));}
}
