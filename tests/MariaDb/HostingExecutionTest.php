<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class HostingExecutionTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\HostingCommandFixture;
    public function testLostDatabaseExecutionOwnershipPreventsCreatePost():void
    {
        $f=$this->commandFixture();$reads=0;$f['transport']->after=function($url)use(&$reads):void{if(str_contains($url,'/listpkgs')&&++$reads===2){$this->db->selectOne('SELECT RELEASE_ALL_LOCKS()');}};
        self::assertFalse($f['app']->provision($f['service']->getId(),3)->isSuccess());self::assertSame([],$this->commandPosts($f));self::assertSame('pending',$f['services']->findServiceById($f['service']->getId())->getStatus());self::assertTrue($f['capacity']->findActiveReservationForService($f['service']->getId())->isCommitted());
    }
    public function testLostExecutionOwnershipAfterRemoteMutationCannotActivate():void
    {
        $f=$this->commandFixture();$f['transport']->after=function($url):void{if(str_contains($url,'/createacct')){$this->db->selectOne('SELECT RELEASE_ALL_LOCKS()');}};
        self::assertFalse($f['app']->provision($f['service']->getId(),3)->isSuccess());self::assertCount(1,$this->commandPosts($f));self::assertSame('pending',$f['services']->findServiceById($f['service']->getId())->getStatus());self::assertSame('reconciliation_required',$f['ops']->listOperationsForService($f['service']->getId())[0]->getStatus());
    }
    public function testCurrentOperationReadsCannotUseAnOldRepeatableReadSnapshot():void
    {
        $f=$this->commandFixture();$this->db->getPdo()->beginTransaction();
        try{
            self::assertSame([],$this->db->select('SELECT * FROM provisioning_operations'));
            $pdo=new \PDO(getenv('COLEZA_TEST_MARIADB_DSN').';dbname='.$this->database,getenv('COLEZA_TEST_MARIADB_USER'),getenv('COLEZA_TEST_MARIADB_PASSWORD'));
            $other=new \Coleza\Domain\Provisioning\Services\ProvisioningOperationService(new \Coleza\Foundation\Database\Connection($pdo),\Coleza\Tests\Support\SecretFixtureKey::encryptor());
            $op=$other->queueOperation($f['service']->getId(),'cpanel','create_account',[],serverId:$f['server']->getId());
            self::assertSame($op->getOperationUuid(),$f['ops']->findActiveOperationForService($f['service']->getId())->getOperationUuid());self::assertCount(1,$f['ops']->listOperationsForService($f['service']->getId()));
        }finally{$this->db->getPdo()->rollBack();}
    }
    public function testPersistentDatabaseSessionCannotOwnProviderExecution():void
    {
        $pdo=new \PDO(getenv('COLEZA_TEST_MARIADB_DSN').';dbname='.$this->database,getenv('COLEZA_TEST_MARIADB_USER'),getenv('COLEZA_TEST_MARIADB_PASSWORD'),[\PDO::ATTR_PERSISTENT=>true]);
        $this->expectException(\LogicException::class);$this->expectExceptionMessage('non-persistent');
        \Coleza\Domain\Provisioning\Services\HostingExecutionLease::acquire(new \Coleza\Foundation\Database\Connection($pdo),1);
    }

}
