<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
use Coleza\Domain\Commerce\Services\ServiceService;
use Coleza\Domain\Servers\Services\ServerService;
use Coleza\Domain\Servers\Capacity\Services\CapacityReservationService;
use Coleza\Domain\Servers\Placement\PlacementEngine;
use Coleza\Domain\Providers\Cpanel\CpanelProvider;
use Coleza\Domain\Providers\Registry\ProviderRegistry;
use Coleza\Domain\Provisioning\Services\ProvisioningOperationService;
use Coleza\Domain\Provisioning\Workflows\{HostingProvisioningWorkflow,HostingProvisioningRequest};
use PHPUnit\Framework\Attributes\DataProvider;
trait HostingDispatchCases
{
    private function hostingFixture():array
    {
        $services=new ServiceService($this->db);$services->ensureTables();$servers=new ServerService($this->db,SecretFixtureKey::encryptor());$servers->ensureTables();
        $capacity=new CapacityReservationService($this->db,$servers);$capacity->ensureTables();$ops=new ProvisioningOperationService($this->db,SecretFixtureKey::encryptor());$ops->ensureTables();
        $server=$servers->createServer(['name'=>'Fixture','hostname'=>'fixture.test','ip_address'=>'127.0.0.2','provider_slug'=>'cpanel','max_accounts'=>100,'auth_secret'=>'fixture-only-token']);
        $service=$services->createService(['user_id'=>1,'product_id'=>1,'domain'=>'client.test','metadata'=>['package_identifier'=>'starter']]);
        $transport=new HostingWorkflowTransport();$transport->memory->stageResponse('listpkgs',200,['metadata'=>['result'=>1],'data'=>['pkg'=>[['name'=>'starter','QUOTA'=>'100','BWLIMIT'=>'1000']]]]);
        $transport->memory->stageResponse('createacct',200,['metadata'=>['result'=>1],'data'=>['ip'=>'127.0.0.2']]);$this->hostingSummary($transport);
        $workflow=new HostingProvisioningWorkflow($services,$servers,new PlacementEngine($servers),$capacity,new ProviderRegistry([new CpanelProvider($transport)]),$ops);
        $request=new HostingProvisioningRequest($service->getId(),username:'clientusr',password:'fixture-account-password',requiredDiskMb:10,requiredBandwidthMb:100,preferredServerId:$server->getId());
        return compact('services','servers','capacity','ops','server','service','transport','workflow','request');
    }
    private function hostingSummary(HostingWorkflowTransport $transport,array $changes=[]):void
    {
        $transport->memory->stageResponse('accountsummary',200,['metadata'=>['result'=>1],'data'=>['acct'=>[array_replace(['user'=>'clientusr','domain'=>'client.test','plan'=>'starter','ip'=>'127.0.0.2','suspended'=>0],$changes)]]]);
    }
    private function creates(array $f):int{return count(array_filter($f['transport']->memory->getRecordedRequests(),static fn($r)=>str_contains($r['url'],'/createacct')));}
    public function testVerifiedAccountActivatesOnceAndRetainsCapacity():void
    {
        $f=$this->hostingFixture();$result=$f['workflow']->execute($f['request']);self::assertTrue($result->isSuccess());self::assertSame('active',$f['services']->findServiceById($f['service']->getId())->getStatus());self::assertSame(1,$this->creates($f));
        self::assertTrue($f['workflow']->execute($f['request'])->isSuccess());self::assertSame(1,$this->creates($f));self::assertTrue($f['capacity']->findActiveReservationForService($f['service']->getId())->isCommitted());
    }
    #[DataProvider('invalidAccounts')]
    public function testIncompleteOrMismatchedRemoteAccountNeverActivates(array $change):void
    {
        $f=$this->hostingFixture();$this->hostingSummary($f['transport'],$change);$result=$f['workflow']->execute($f['request']);self::assertFalse($result->isSuccess());self::assertSame('pending',$f['services']->findServiceById($f['service']->getId())->getStatus());
        self::assertSame('reconciliation_required',$f['ops']->findOperationByUuid($result->getOperationUuid())->getStatus());self::assertTrue($f['capacity']->findActiveReservationForService($f['service']->getId())->isCommitted());
    }
    public static function invalidAccounts():array{return ['domain'=>[['domain'=>'other.test']],'plan'=>[['plan'=>'foreign']],'suspended'=>[['suspended'=>1]],'ip'=>[['ip'=>'not-ip']],'boolean'=>[['suspended'=>false]]];}
    public function testUnknownRemoteFailureDoesNotFreeCapacityOrAuthorizeSecondCreate():void
    {
        $f=$this->hostingFixture();$f['transport']->after=static function($url):void{if(str_contains($url,'/createacct')){throw new \RuntimeException('Response lost after remote request.');}};
        $first=$f['workflow']->execute($f['request']);self::assertFalse($first->isSuccess());$held=$f['capacity']->findActiveReservationForService($f['service']->getId());self::assertNotNull($held);self::assertTrue($held->isCommitted());
        self::assertSame('reconciliation_required',$f['ops']->findOperationByUuid($first->getOperationUuid())->getStatus());$second=$f['workflow']->execute($f['request']);self::assertFalse($second->isSuccess());self::assertSame(1,$this->creates($f));
    }
    public function testProcessingOperationBlocksAnotherDispatch():void
    {
        $f=$this->hostingFixture();$op=$f['ops']->queueOperation($f['service']->getId(),'cpanel','create_account',[],serverId:$f['server']->getId());$this->db->update('provisioning_operations',['status'=>'processing'],'id=:id',['id'=>$op->getId()]);
        self::assertFalse($f['workflow']->execute($f['request'])->isSuccess());self::assertSame(0,$this->creates($f));
    }
    public function testReentrantWorkerCannotCreateAgainWhileFirstRequestIsInFlight():void
    {
        $f=$this->hostingFixture();$nested=null;$f['transport']->after=function($url)use($f,&$nested):void{if(str_contains($url,'/createacct')){$nested=$f['workflow']->execute($f['request']);}};
        self::assertTrue($f['workflow']->execute($f['request'])->isSuccess());self::assertNotNull($nested);self::assertFalse($nested->isSuccess());self::assertSame(1,$this->creates($f));
    }
    public function testCallerTransactionIsRejectedBeforeAnyRemoteRequest():void
    {
        $f=$this->hostingFixture();$this->db->getPdo()->beginTransaction();
        try{try{$f['workflow']->execute($f['request']);self::fail('Caller transaction crossed remote effects.');}catch(\LogicException){self::assertSame([],$f['transport']->memory->getRecordedRequests());self::assertTrue($this->db->getPdo()->inTransaction());}}finally{$this->db->getPdo()->rollBack();}
    }
    public function testPasswordIsStoredOnlyInEncryptedOperationPayload():void
    {
        $f=$this->hostingFixture();self::assertTrue($f['workflow']->execute($f['request'])->isSuccess());$service=$this->db->selectOne('SELECT password_encrypted FROM services WHERE id=?',[$f['service']->getId()]);self::assertNull($service['password_encrypted']);
        $row=$this->db->selectOne('SELECT payload_json,result_json FROM provisioning_operations WHERE service_id=?',[$f['service']->getId()]);self::assertStringNotContainsString('fixture-account-password',json_encode($row));
        $op=$f['ops']->listOperationsForService($f['service']->getId())[0];self::assertSame('fixture-account-password',$op->getPayload()['password']);
    }
    public function testLateAuditFailureRollsBackActivationAndPlacementTogether():void
    {
        $f=$this->hostingFixture();$sql=$this->db->getDriverName()==='mysql'?"CREATE TRIGGER hosting_audit_fail BEFORE INSERT ON provisioning_audit_logs FOR EACH ROW BEGIN IF NEW.success=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture'; END IF; END":"CREATE TRIGGER hosting_audit_fail BEFORE INSERT ON provisioning_audit_logs WHEN NEW.success=1 BEGIN SELECT RAISE(ABORT,'fixture'); END";$this->db->statement($sql);
        $result=$f['workflow']->execute($f['request']);self::assertFalse($result->isSuccess());self::assertSame('pending',$f['services']->findServiceById($f['service']->getId())->getStatus());self::assertSame('reserved',$f['services']->getPlacementForService($f['service']->getId())->getStatus());self::assertTrue($f['capacity']->findActiveReservationForService($f['service']->getId())->isCommitted());
    }
    public function testServiceChangedDuringRemoteProbeCannotBeActivated():void
    {
        $f=$this->hostingFixture();$f['transport']->after=function($url)use($f):void{if(str_contains($url,'/accountsummary')){$f['services']->updateService($f['service']->getId(),['domain'=>'changed.test']);}};
        self::assertFalse($f['workflow']->execute($f['request'])->isSuccess());self::assertSame('pending',$f['services']->findServiceById($f['service']->getId())->getStatus());
    }
    public function testCommittedDispatchCapacityDoesNotExpireDuringRemoteCall():void
    {
        $f=$this->hostingFixture();$f['transport']->after=function($url)use($f):void{if(str_contains($url,'/createacct')){$this->db->statement("UPDATE capacity_reservations SET expires_at='2000-01-01 00:00:00' WHERE service_id=?",[$f['service']->getId()]);self::assertSame([],$f['capacity']->expireStaleReservations());}};
        self::assertTrue($f['workflow']->execute($f['request'])->isSuccess());self::assertTrue($f['capacity']->findActiveReservationForService($f['service']->getId())->isCommitted());
    }
    public function testExplicitMatchingProbeRecoversHeldDispatchWithoutAnotherCreate():void
    {
        $f=$this->hostingFixture();$f['transport']->after=static function($url):void{if(str_contains($url,'/createacct')){throw new \RuntimeException('Lost response.');}};
        $failed=$f['workflow']->execute($f['request']);$f['transport']->after=null;
        $probe=new \Coleza\Domain\Provisioning\Reconciliation\UncertainResponseReconciliationService($f['servers'],new ProviderRegistry([new CpanelProvider($f['transport'])]),$f['services'],$f['ops']);
        self::assertTrue($probe->reconcile($f['ops']->findOperationByUuid($failed->getOperationUuid()))->isExists());self::assertSame(1,$this->creates($f));self::assertSame('active',$f['services']->findServiceById($f['service']->getId())->getStatus());self::assertTrue($f['capacity']->findActiveReservationForService($f['service']->getId())->isCommitted());
    }
    public function testReconciliationCannotIgnoreChangedDurableServiceBinding():void
    {
        $f=$this->hostingFixture();$this->hostingSummary($f['transport'],['suspended'=>1]);$failed=$f['workflow']->execute($f['request']);$this->hostingSummary($f['transport']);
        $f['services']->updateService($f['service']->getId(),['notes'=>'service edited during uncertainty']);
        $probe=new \Coleza\Domain\Provisioning\Reconciliation\UncertainResponseReconciliationService($f['servers'],new ProviderRegistry([new CpanelProvider($f['transport'])]),$f['services'],$f['ops']);
        self::assertTrue($probe->reconcile($f['ops']->findOperationByUuid($failed->getOperationUuid()))->isUnknown());self::assertSame('pending',$f['services']->findServiceById($f['service']->getId())->getStatus());self::assertSame(1,$this->creates($f));
    }
    public function testPreferredServerCannotBypassProviderBinding():void
    {
        $f=$this->hostingFixture();$this->db->update('servers',['provider_slug'=>'other'],'id=:id',['id'=>$f['server']->getId()]);self::assertFalse($f['workflow']->execute($f['request'])->isSuccess());self::assertSame(0,$this->creates($f));
    }

    public function testStoredEncryptedCredentialCannotBeSentAsPlaintext():void
    {
        $f=$this->hostingFixture();$f['services']->updateService($f['service']->getId(),['password_encrypted'=>'legacy-encrypted-ciphertext']);self::assertFalse($f['workflow']->execute($f['request'])->isSuccess());self::assertSame(0,$this->creates($f));
    }
    public function testServerConnectionChangedDuringPackageReadCannotDispatchToStaleEndpoint():void
    {
        $f=$this->hostingFixture();$f['transport']->after=function($url)use($f):void{if(str_contains($url,'/listpkgs')){$this->db->update('servers',['port'=>2088],'id=:id',['id'=>$f['server']->getId()]);}};
        self::assertFalse($f['workflow']->execute($f['request'])->isSuccess());self::assertSame(0,$this->creates($f));self::assertNull($f['capacity']->findActiveReservationForService($f['service']->getId()));
    }
    public function testMixedConnectionWorkflowIsRejectedBeforeEffects():void
    {
        $f=$this->hostingFixture();$other=new \Coleza\Foundation\Database\Connection(new \PDO('sqlite::memory:'));
        $this->expectException(\LogicException::class);new HostingProvisioningWorkflow($f['services'],new ServerService($other,SecretFixtureKey::encryptor()),new PlacementEngine($f['servers']),$f['capacity'],new ProviderRegistry([new CpanelProvider($f['transport'])]),$f['ops']);
    }

}
