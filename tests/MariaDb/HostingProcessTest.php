<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class HostingProcessTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\HostingProtocolCases;
    protected function setUp():void{parent::setUp();$this->protocol=new \Coleza\Tests\Support\HostingProtocolFixture();}
    protected function tearDown():void{try{if($this->protocol!==null){$this->protocol->close();$this->protocol=null;}}finally{parent::tearDown();}}
}
