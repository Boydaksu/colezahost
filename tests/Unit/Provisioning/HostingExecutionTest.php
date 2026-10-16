<?php
declare(strict_types=1);
namespace Coleza\Tests\Unit\Provisioning;
final class HostingExecutionTest extends \PHPUnit\Framework\TestCase
{
    use \Coleza\Tests\Support\HostingCommandFixture;
    private \Coleza\Foundation\Database\Connection $db;
    protected function setUp():void{$this->db=new \Coleza\Foundation\Database\Connection(new \PDO('sqlite::memory:'));}
    public function testLiveCreateCannotBeCompletedByReentrantRecovery():void
    {
        $f=$this->commandFixture();$nested=null;$f['transport']->after=function($url)use($f,&$nested):void{
            if(str_contains($url,'/createacct')){$op=$f['ops']->findActiveOperationForService($f['service']->getId());try{$nested=$f['app']->reconcile($op->getOperationUuid(),4);}catch(\RuntimeException){}}
        };
        try{$f['app']->provision($f['service']->getId(),3);}catch(\RuntimeException|\LogicException){}
        self::assertFalse($nested instanceof \Coleza\Domain\Provisioning\Reconciliation\ReconciliationResult&&$nested->isExists(),'A live creator was overtaken by recovery.');
        self::assertSame('active',$f['services']->findServiceById($f['service']->getId())->getStatus());
    }
    public function testLiveSuspendCannotBeCompletedByReentrantRecovery():void
    {
        $f=$this->commandFixture();$this->provisionCommand($f);$nested=null;$original=$f['transport']->after;
        $f['transport']->after=function($url)use($f,&$nested,$original):void{
            $original($url);if(str_contains($url,'/suspendacct')){$op=$f['ops']->findActiveOperationForService($f['service']->getId());try{$nested=$f['app']->reconcile($op->getOperationUuid(),4);}catch(\RuntimeException){}}
        };
        try{$f['app']->suspend($f['service']->getId(),3,'Fixture');}catch(\RuntimeException|\LogicException){}
        self::assertNull($nested,'A live lifecycle worker was overtaken by recovery.');
        self::assertSame('suspended',$f['services']->findServiceById($f['service']->getId())->getStatus());
    }
}
