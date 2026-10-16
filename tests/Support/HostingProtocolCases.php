<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
use Coleza\Application\Provisioning\HostingApplicationService;
use Coleza\Domain\Providers\Cpanel\{CpanelProvider,CpanelCurlTransport};
use Coleza\Domain\Providers\Registry\ProviderRegistry;
use Coleza\Domain\Provisioning\Workflows\{HostingProvisioningWorkflow,HostingLifecycleWorkflow};
use Coleza\Domain\Servers\Placement\PlacementEngine;
use PHPUnit\Framework\Attributes\DataProvider;
trait HostingProtocolCases
{
    use HostingCommandFixture;
    private ?HostingProtocolFixture $protocol=null;
    private function networkFixture(int $timeout=10):array
    {
        $f=$this->commandFixture(['hostname'=>'127.0.0.1','port'=>$this->protocol->ready['port'],'metadata'=>['timeout_seconds'=>$timeout]]);
        $providers=new ProviderRegistry([new CpanelProvider(new CpanelCurlTransport(caBundle:$this->protocol->ready['ca_bundle']))]);
        $f['app']=new HostingApplicationService(new HostingProvisioningWorkflow($f['services'],$f['servers'],new PlacementEngine($f['servers']),$f['capacity'],$providers,$f['ops']),new HostingLifecycleWorkflow($f['services'],$f['servers'],$f['capacity'],$f['ops'],$providers),$f['ops'],$f['services'],$f['servers'],$providers);
        return $f;
    }
    private function config(array $f,string $action,?string $uuid=null):array
    {
        $driver=$this->db->getDriverName();$database=$driver==='mysql'?$this->database:$this->protocol->root.'/hosting.sqlite';
        return ['driver'=>$driver,'database'=>$database,'service_id'=>$f['service']->getId(),'action'=>$action,'uuid'=>$uuid];
    }
    private function currentOp(array $f)
    {
        $ops=$f['ops']->listOperationsForService($f['service']->getId());self::assertNotEmpty($ops);return $ops[0];
    }
    private function held(array $f,string $status='pending'):void
    {
        self::assertSame($status,$f['services']->findServiceById($f['service']->getId())->getStatus());self::assertTrue($f['capacity']->findActiveReservationForService($f['service']->getId())->isCommitted());
        self::assertContains($this->currentOp($f)->getStatus(),['processing','reconciliation_required']);
    }
    private function start(array $f,string $action,string $name='worker',bool $barrier=false,?string $uuid=null):void{$this->protocol->startWorker($this->config($f,$action,$uuid),$name,$barrier);}
    public function testRealTlsPaidOrderCompletesAllLifecycleCommands():void
    {
        $f=$this->networkFixture();$id=$f['service']->getId();self::assertTrue($f['app']->provision($id,3)->isSuccess());
        foreach(['suspend'=>'suspended','unsuspend'=>'active','terminate'=>'terminated']as$action=>$status){self::assertSame('completed',$f['app']->$action($id,3,'HTTPS fixture')->getStatus());self::assertSame($status,$f['services']->findServiceById($id)->getStatus());}
        foreach(['createacct','suspendacct','unsuspendacct','removeacct']as$endpoint){self::assertCount(1,$this->protocol->requests($endpoint));}
        self::assertNull($f['capacity']->findActiveReservationForService($id));foreach($this->protocol->requests()as$r){self::assertTrue($r['authorized']);self::assertSame(['1'],$r['query']['api.version']);}
    }
    #[DataProvider('mutationFailures')]
    public function testRealTlsCreateFailureHoldsAndRecoversWithoutReplay(string $failure):void
    {
        $f=$this->networkFixture($failure==='timeout'?1:10);$this->protocol->mode(['endpoint'=>'createacct','failure'=>$failure]);self::assertFalse($f['app']->provision($f['service']->getId(),3)->isSuccess());$this->held($f);
        self::assertFalse($f['app']->provision($f['service']->getId(),3)->isSuccess());self::assertCount(1,$this->protocol->requests('createacct'));
        $this->protocol->mode([]);$recovery=$f['app']->reconcile($this->currentOp($f)->getOperationUuid(),4);self::assertTrue($recovery->isExists(),json_encode($recovery->toArray()));self::assertCount(1,$this->protocol->requests('createacct'));
    }
    public static function mutationFailures():array{return [['drop'],['timeout'],['http503'],['malformed'],['negative']];}
    #[DataProvider('probeFailures')]
    public function testRealTlsVerificationFailureCannotActivate(string $failure):void
    {
        $f=$this->networkFixture();$this->protocol->mode(['endpoint'=>'accountsummary','failure'=>$failure]);self::assertFalse($f['app']->provision($f['service']->getId(),3)->isSuccess());$this->held($f);
        $this->protocol->mode([]);$recovery=$f['app']->reconcile($this->currentOp($f)->getOperationUuid(),4);self::assertTrue($recovery->isExists(),json_encode($recovery->toArray()));self::assertCount(1,$this->protocol->requests('createacct'));
    }
    public static function probeFailures():array{return [['http503'],['malformed'],['mismatch'],['ambiguous']];}
    #[DataProvider('lifecycleActions')]
    public function testRealTlsLostLifecycleResponseRecoversWithoutReplay(string $action,string $endpoint,string $target):void
    {
        $f=$this->networkFixture();$id=$f['service']->getId();self::assertTrue($f['app']->provision($id,3)->isSuccess());$prior='active';
        if($action==='unsuspend'){self::assertSame('completed',$f['app']->suspend($id,3,'Fixture')->getStatus());$prior='suspended';}
        $this->protocol->mode(['endpoint'=>$endpoint,'failure'=>'drop']);$op=$f['app']->$action($id,3,'Fixture');self::assertSame('reconciliation_required',$op->getStatus());$this->held($f,$prior);
        $this->protocol->mode([]);self::assertSame('completed',$f['app']->reconcile($op->getOperationUuid(),4)->getStatus());self::assertSame($target,$f['services']->findServiceById($id)->getStatus());self::assertCount(1,$this->protocol->requests($endpoint));
    }
    public static function lifecycleActions():array{return [['suspend','suspendacct','suspended'],['unsuspend','unsuspendacct','active'],['terminate','removeacct','terminated']];}
    public function testFourSeparateProcessesCannotDispatchDuplicateCreate():void
    {
        $f=$this->networkFixture();$this->protocol->mode(['pause'=>'createacct']);
        for($i=0;$i<4;$i++){$this->start($f,'provision','parallel'.$i,true);}file_put_contents($this->protocol->root.'/go','go');
        $this->protocol->wait(fn()=>is_file($this->protocol->root.'/paused'));
        $this->protocol->wait(fn()=>count(glob($this->protocol->root.'/parallel*.done'))===3);
        self::assertCount(1,$this->protocol->requests('createacct'));self::assertSame('processing',$this->currentOp($f)->getStatus());$this->protocol->release();
        $success=0;for($i=0;$i<4;$i++){$result=$this->protocol->result('parallel'.$i);if($result['success']){$success++;}else{self::assertSame('HOSTING_EXECUTION_BUSY',$result['result']['error_code']??null,json_encode($result));}}
        self::assertSame(1,$success);self::assertCount(1,$f['ops']->listOperationsForService($f['service']->getId()));self::assertCount(1,$this->protocol->requests('createacct'));
        self::assertSame('active',$f['services']->findServiceById($f['service']->getId())->getStatus());
    }
    public function testSeparateLiveRecoveryCannotOvertakeCreateWorker():void
    {
        $f=$this->networkFixture();$this->protocol->mode(['pause'=>'createacct']);$this->start($f,'provision');$this->protocol->wait(fn()=>is_file($this->protocol->root.'/paused'));$op=$this->currentOp($f);
        $requests=count($this->protocol->requests());$this->start($f,'reconcile','recovery',uuid:$op->getOperationUuid());self::assertFalse($this->protocol->result('recovery')['success']);self::assertCount($requests,$this->protocol->requests());
        self::assertSame('processing',$this->currentOp($f)->getStatus());$this->protocol->release();self::assertTrue($this->protocol->result('worker')['success']);
    }
    public function testKilledWorkerAfterRemoteCreateCanRecoverFromFreshProcess():void
    {
        $f=$this->networkFixture();$this->protocol->mode(['pause'=>'createacct']);$this->start($f,'provision');$this->protocol->wait(fn()=>is_file($this->protocol->root.'/paused'));$op=$this->currentOp($f);$this->held($f);
        $this->protocol->kill('worker');$this->protocol->release();$this->protocol->mode([]);$this->start($f,'reconcile','recovery',uuid:$op->getOperationUuid());self::assertTrue($this->protocol->result('recovery')['success']);
        self::assertSame('active',$f['services']->findServiceById($f['service']->getId())->getStatus());self::assertCount(1,$this->protocol->requests('createacct'));self::assertSame('completed',$this->currentOp($f)->getStatus());
    }
    public function testKilledWorkerBeforeClaimDoesNotLeaveReservationOrOperation():void
    {
        $f=$this->networkFixture();$this->protocol->mode(['pause'=>'listpkgs']);$this->start($f,'provision');$this->protocol->wait(fn()=>is_file($this->protocol->root.'/paused'));self::assertSame([],$f['ops']->listOperationsForService($f['service']->getId()));
        $this->protocol->kill('worker');$this->protocol->release();$this->protocol->mode([]);self::assertNull($f['capacity']->findActiveReservationForService($f['service']->getId()));self::assertTrue($f['app']->provision($f['service']->getId(),3)->isSuccess());self::assertCount(1,$this->protocol->requests('createacct'));
    }
    public function testKilledWorkerAfterClaimBeforePostRemainsHeldWithoutAutomaticReplay():void
    {
        $f=$this->networkFixture();$this->protocol->mode(['pause'=>'listpkgs','pause_nth'=>2]);$this->start($f,'provision');$this->protocol->wait(fn()=>is_file($this->protocol->root.'/paused'));$this->held($f);
        $this->protocol->kill('worker');$this->protocol->release();$this->protocol->mode([]);self::assertTrue($f['app']->reconcile($this->currentOp($f)->getOperationUuid(),4)->isUnknown());self::assertFalse($f['app']->provision($f['service']->getId(),3)->isSuccess());
        $this->held($f);self::assertSame([],$this->protocol->requests('createacct'));
    }
    #[DataProvider('lifecycleActions')]
    public function testKilledLifecycleWorkerRecoversWithoutRepeatingMutation(string $action,string $endpoint,string $target):void
    {
        $f=$this->networkFixture();$id=$f['service']->getId();self::assertTrue($f['app']->provision($id,3)->isSuccess());$prior='active';
        if($action==='unsuspend'){$f['app']->suspend($id,3,'Fixture');$prior='suspended';}
        $this->protocol->mode(['pause'=>$endpoint]);$this->start($f,$action);$this->protocol->wait(fn()=>is_file($this->protocol->root.'/paused'));$op=$this->currentOp($f);$this->held($f,$prior);
        $count=count($this->protocol->requests());$this->start($f,'reconcile','live-recovery',uuid:$op->getOperationUuid());self::assertFalse($this->protocol->result('live-recovery')['success']);self::assertCount($count,$this->protocol->requests());
        $this->protocol->kill('worker');$this->protocol->release();$this->protocol->mode([]);$this->start($f,'reconcile','recovery',uuid:$op->getOperationUuid());self::assertTrue($this->protocol->result('recovery')['success']);
        self::assertSame($target,$f['services']->findServiceById($id)->getStatus());self::assertCount(1,$this->protocol->requests($endpoint));
    }
    public function testPaidPermissionRevokedWhileHttpsRequestIsPendingCannotActivate():void
    {
        $f=$this->networkFixture();$this->protocol->mode(['pause'=>'createacct']);$this->start($f,'provision');$this->protocol->wait(fn()=>is_file($this->protocol->root.'/paused'));$this->db->statement('UPDATE users SET is_active=0 WHERE id=3');
        $this->protocol->release();self::assertFalse($this->protocol->result('worker')['success']);$this->held($f);$this->protocol->mode([]);$recovery=$f['app']->reconcile($this->currentOp($f)->getOperationUuid(),4);self::assertTrue($recovery->isExists(),json_encode($recovery->toArray()));self::assertCount(1,$this->protocol->requests('createacct'));
    }
    public function testSeparateConflictingLifecycleProcessesSendOnlyOneMutation():void
    {
        $f=$this->networkFixture();self::assertTrue($f['app']->provision($f['service']->getId(),3)->isSuccess());$this->protocol->mode(['pause'=>['suspendacct','removeacct']]);
        foreach(['suspend','terminate','suspend','terminate']as$i=>$action){$this->start($f,$action,'conflict'.$i,true);}file_put_contents($this->protocol->root.'/go','go');
        $this->protocol->wait(fn()=>is_file($this->protocol->root.'/paused'));$this->protocol->wait(fn()=>count(glob($this->protocol->root.'/conflict*.done'))===3);
        self::assertFalse($f['app']->provision($f['service']->getId(),3)->isSuccess());$this->protocol->release();$success=0;
        for($i=0;$i<4;$i++){$result=$this->protocol->result('conflict'.$i);if($result['success']){$success++;}else{self::assertSame(\Coleza\Domain\Provisioning\Services\HostingExecutionBusy::class,$result['error']??null,json_encode($result));}}
        self::assertSame(1,$success);self::assertSame(1,count($this->protocol->requests('suspendacct'))+count($this->protocol->requests('removeacct')));self::assertCount(2,$f['ops']->listOperationsForService($f['service']->getId()));self::assertSame('completed',$this->currentOp($f)->getStatus());
        self::assertContains($f['services']->findServiceById($f['service']->getId())->getStatus(),['suspended','terminated']);self::assertCount(1,$this->protocol->requests('createacct'));
    }

}
