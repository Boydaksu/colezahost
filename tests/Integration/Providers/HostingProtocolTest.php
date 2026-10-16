<?php
declare(strict_types=1);
namespace Coleza\Tests\Integration\Providers;
final class HostingProtocolTest extends \PHPUnit\Framework\TestCase
{
    use \Coleza\Tests\Support\HostingProtocolCases;
    private ?\Coleza\Foundation\Database\Connection $db=null;
    protected function setUp():void
    {
        $this->protocol=new \Coleza\Tests\Support\HostingProtocolFixture();$this->db=new \Coleza\Foundation\Database\Connection(new \PDO('sqlite:'.$this->protocol->root.'/hosting.sqlite'));$this->db->statement('PRAGMA busy_timeout=5000');
    }
    protected function tearDown():void{$this->db=null;if($this->protocol!==null){$this->protocol->close();$this->protocol=null;}}
}
