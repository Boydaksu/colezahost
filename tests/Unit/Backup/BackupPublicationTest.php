<?php
declare(strict_types=1);
namespace Coleza\Tests\Unit\Backup;

use Coleza\Domain\Backup\{BackupScope,BackupDestinationType,EnterpriseBackupService};
use Coleza\Domain\Backup\Storage\{BackupStorageAdapterInterface,LocalBackupStorageAdapter,S3BackupStorageAdapter,SftpBackupStorageAdapter};
use PHPUnit\Framework\TestCase;

final class BackupPublicationTest extends TestCase
{
    private string $root;
    protected function setUp(): void
    {
        $this->root=sys_get_temp_dir().'/coleza_publication_'.bin2hex(random_bytes(8));mkdir($this->root,0700,true);file_put_contents($this->root.'/source.txt','İstanbul backup');
    }
    protected function tearDown(): void
    {
        $this->removeOwned($this->root);
    }
    private function removeOwned(string $path): void
    {
        if(is_link($path)||is_file($path)){unlink($path);return;}
        if(!is_dir($path)){return;}foreach(scandir($path)as$name){if($name!=='.'&&$name!=='..'){$this->removeOwned($path.'/'.$name);}}rmdir($path);
    }
    private function brokenDestination(string $mode): BackupStorageAdapterInterface
    {
        return new class($mode) implements BackupStorageAdapterInterface {
            private string $archive='';private string $manifest='';
            public function __construct(private string $mode){}
            public function getType(): BackupDestinationType{return BackupDestinationType::S3;}
            public function store(string $id,string $archive,string $manifest): bool {$this->archive=(string)file_get_contents($archive);$this->manifest=(string)file_get_contents($manifest);if($this->mode==='throw'){throw new \RuntimeException('Transport interrupted');}return $this->mode!=='false';}
            public function retrieve(string $id,string $dir): bool {if($this->mode==='missing'){return false;}if(!is_dir($dir)){mkdir($dir,0700,true);}file_put_contents($dir.'/'.$id.'.zip',$this->mode==='corrupt'?'broken':$this->archive);if($this->mode==='partial'){return true;}if($this->mode==='substitute'){$data=json_decode($this->manifest,true,flags:JSON_THROW_ON_ERROR);$changed='different package';file_put_contents($dir.'/'.$id.'.zip',$changed);$data['file_size_bytes']=strlen($changed);$data['sha256_checksum']=hash('sha256',$changed);file_put_contents($dir.'/'.$id.'.manifest.json',json_encode($data,JSON_THROW_ON_ERROR));}else{file_put_contents($dir.'/'.$id.'.manifest.json',$this->manifest);}return true;}
            public function delete(string $id): bool{return true;}
            public function exists(string $id): bool{return true;}
            public function listBackups(): array{return [];}
        };
    }
    private function rejectAndKeepLocal(string $mode): void
    {
        $failed=false;
        try{(new EnterpriseBackupService($this->root.'/work'))->createBackup(BackupScope::FILES,$this->brokenDestination($mode),[$this->root.'/source.txt']);}catch(\RuntimeException){$failed=true;}
        self::assertTrue($failed,'Unverified delivery reported success');self::assertCount(1,glob($this->root.'/work/*.zip'));self::assertCount(1,glob($this->root.'/work/*.manifest.json'));
    }
    public function testFalseStoreNeverReportsSuccessOrDeletesLocalCopy(): void {$this->rejectAndKeepLocal('false');}
    public function testMissingReadbackNeverReportsSuccessOrDeletesLocalCopy(): void {$this->rejectAndKeepLocal('missing');}
    public function testCorruptReadbackNeverReportsSuccessOrDeletesLocalCopy(): void {$this->rejectAndKeepLocal('corrupt');}
    public function testUnconfiguredS3DoesNotSilentlySimulateSuccess(): void
    {
        $this->expectException(\RuntimeException::class);(new EnterpriseBackupService($this->root.'/work'))->createBackup(BackupScope::FILES,new S3BackupStorageAdapter(),[$this->root.'/source.txt']);
    }
    public function testProductionSftpDoesNotPretendTransferSucceeded(): void
    {
        $this->expectException(\RuntimeException::class);(new EnterpriseBackupService($this->root.'/work'))->createBackup(BackupScope::FILES,new SftpBackupStorageAdapter([],false),[$this->root.'/source.txt']);
    }
    public function testDatabaseScopeWithoutDatabaseIsRejected(): void
    {
        $warnings=[];$failed=false;set_error_handler(static function(int $level,string $message)use(&$warnings):bool{$warnings[]=$message;return true;});
        try{(new EnterpriseBackupService($this->root.'/work'))->createBackup(BackupScope::DATABASE,new LocalBackupStorageAdapter($this->root.'/store'));}catch(\RuntimeException){$failed=true;}finally{restore_error_handler();}
        self::assertSame([],$warnings,'Admission must fail before archive operations');self::assertTrue($failed);self::assertSame([],glob($this->root.'/work/*.manifest.json'));
    }
    private function sourcePackage(string $id,string $content='original',int $ageDays=0): array
    {
        $archive=$this->root.'/'.$id.'.source.zip';$path=$this->root.'/'.$id.'.source.json';file_put_contents($archive,$content);
        $manifest=new \Coleza\Domain\Backup\BackupManifest($id,'files','1.0.0',strlen($content),hash('sha256',$content),date('c',strtotime('-'.$ageDays.' days')));
        file_put_contents($path,json_encode($manifest->toArray(),JSON_THROW_ON_ERROR));return [$archive,$path,$manifest];
    }
    public function testVerifiedExplicitSimulationCleansOnlyAfterReadback(): void
    {
        $destination=new S3BackupStorageAdapter([],true);$result=(new EnterpriseBackupService($this->root.'/work'))->createBackup(BackupScope::FILES,$destination,[$this->root.'/source.txt']);self::assertTrue($result['verified']);self::assertTrue($destination->exists($result['manifest']->getBackupId()));self::assertSame([],glob($this->root.'/work/*.zip'));self::assertSame([],glob($this->root.'/work/*.manifest.json'));
    }
    public function testLocalPackageIsRetainedAndIndependentReadbackMatches(): void
    {
        $destination=new LocalBackupStorageAdapter($this->root.'/store');$result=(new EnterpriseBackupService($this->root.'/work'))->createBackup(BackupScope::FILES,$destination,[$this->root.'/source.txt']);$id=$result['manifest']->getBackupId();self::assertTrue($result['verified']);self::assertTrue(is_file($this->root.'/work/'.$id.'.zip'));self::assertTrue($destination->retrieve($id,$this->root.'/restore'));self::assertSame($result['manifest']->getSha256Checksum(),hash_file('sha256',$this->root.'/restore/'.$id.'.zip'));
    }
    public function testSameWorkingAndStorageDirectoryDoesNotOverwriteOrDeadlock(): void
    {
        $destination=new LocalBackupStorageAdapter($this->root.'/same');$result=(new EnterpriseBackupService($this->root.'/same'))->createBackup(BackupScope::FILES,$destination,[$this->root.'/source.txt']);self::assertTrue($destination->exists($result['manifest']->getBackupId()));self::assertCount(1,$destination->listBackups());
    }
    public function testLocalCollisionRetainsPreviouslyPublishedBytes(): void
    {
        $destination=new LocalBackupStorageAdapter($this->root.'/store');[$archive,$manifest]=$this->sourcePackage('stable');$destination->store('stable',$archive,$manifest);$before=file_get_contents($this->root.'/store/stable.zip');[$archive,$manifest]=$this->sourcePackage('stable','replacement');
        try{$destination->store('stable',$archive,$manifest);self::fail('Existing backup overwritten');}catch(\RuntimeException){self::assertSame($before,file_get_contents($this->root.'/store/stable.zip'));self::assertTrue($destination->exists('stable'));}
    }
    public function testLocalStoreIsIdempotentAndRequiresManifest(): void
    {
        $destination=new LocalBackupStorageAdapter($this->root.'/store');[$archive,$manifest]=$this->sourcePackage('stable');self::assertTrue($destination->store('stable',$archive,$manifest));self::assertTrue($destination->store('stable',$archive,$manifest));self::assertSame(['stable'],$destination->listBackups());
        $this->expectException(\RuntimeException::class);$destination->store('another',$archive,$this->root.'/missing.json');
    }
    public function testTraversalAndSymlinkLikeIdentifiersAreRejectedBeforeIo(): void
    {
        $destination=new LocalBackupStorageAdapter($this->root.'/store');foreach(['../escape','a/b','a\\b','x.zip','',str_repeat('a',129)]as$id){try{$destination->exists($id);self::fail('Unsafe ID accepted');}catch(\RuntimeException){self::assertSame([],$destination->listBackups());}}
    }
    public function testPartialLocalPublicationIsNotVisibleAsBackup(): void
    {
        $destination=new LocalBackupStorageAdapter($this->root.'/store');file_put_contents($this->root.'/store/orphan.zip','orphan');self::assertFalse($destination->exists('orphan'));self::assertSame([],$destination->listBackups());self::assertFalse($destination->retrieve('orphan',$this->root.'/restore'));
    }
    public function testLocalCorruptionIsDetectedBeforeRetrieve(): void
    {
        $destination=new LocalBackupStorageAdapter($this->root.'/store');[$archive,$manifest]=$this->sourcePackage('stable');$destination->store('stable',$archive,$manifest);file_put_contents($this->root.'/store/stable.zip','tampered');$this->expectException(\RuntimeException::class);$destination->retrieve('stable',$this->root.'/restore');
    }
    public function testMissingAndDuplicateRequestedFileNamesFailBeforePackageCreation(): void
    {
        mkdir($this->root.'/a');mkdir($this->root.'/b');file_put_contents($this->root.'/a/same.txt','a');file_put_contents($this->root.'/b/same.txt','b');
        foreach([[$this->root.'/missing.txt'],[$this->root.'/a/same.txt',$this->root.'/b/same.txt']]as$files){try{(new EnterpriseBackupService($this->root.'/work'))->createBackup(BackupScope::FILES,new LocalBackupStorageAdapter($this->root.'/store'),$files);self::fail('Partial file scope accepted');}catch(\RuntimeException){self::assertSame([],glob($this->root.'/work/*.zip'));}}
    }
    public function testEncryptionWithoutKeyFailsBeforePlaintextArchiveIsCreated(): void
    {
        try{(new EnterpriseBackupService($this->root.'/work'))->createBackup(BackupScope::FILES,new LocalBackupStorageAdapter($this->root.'/store'),[$this->root.'/source.txt'],true);self::fail('Missing encryption accepted');}catch(\RuntimeException){self::assertSame([],glob($this->root.'/work/*.zip'));}
    }
    public function testRetentionPreservesUnknownHistoryAndLastKnownExpiredCopy(): void
    {
        $destination=new LocalBackupStorageAdapter($this->root.'/store');[$a,$m,$newer]=$this->sourcePackage('newer','a',20);$destination->store('newer',$a,$m);[$a,$m,$older]=$this->sourcePackage('older','b',40);$destination->store('older',$a,$m);
        $policy=new \Coleza\Domain\Backup\BackupRetentionPolicy(1,1);$result=$policy->apply($destination,[]);self::assertCount(2,$result['retained']);self::assertSame([],$result['pruned']);$result=$policy->apply($destination,['newer'=>$newer,'older'=>$older]);self::assertSame(['newer'],$result['retained']);self::assertSame(['older'],$result['pruned']);self::assertTrue($destination->exists('newer'));
    }
    public function testAutomaticRetentionNowVerifiesHistoryAndKeepsTheNewDelivery(): void
    {
        $destination=new LocalBackupStorageAdapter($this->root.'/store');$service=new EnterpriseBackupService($this->root.'/work');for($i=0;$i<3;$i++){$result=$service->createBackup(BackupScope::FILES,$destination,[$this->root.'/source.txt'],retentionPolicy:new \Coleza\Domain\Backup\BackupRetentionPolicy(1,1));self::assertSame([$result['manifest']->getBackupId()],$destination->listBackups());}
    }
    public function testCurrentDeliveryIsProtectedEvenWhenHistoryHasAFutureTimestamp(): void
    {
        $destination=new LocalBackupStorageAdapter($this->root.'/store');[$a,$m]=$this->sourcePackage('future','old contents',-5);$destination->store('future',$a,$m);
        $result=(new EnterpriseBackupService($this->root.'/work'))->createBackup(BackupScope::FILES,$destination,[$this->root.'/source.txt'],retentionPolicy:new \Coleza\Domain\Backup\BackupRetentionPolicy(1,1));
        self::assertSame([$result['manifest']->getBackupId()],$destination->listBackups());
    }
    public function testTransportExceptionRetainsSourcePair(): void {$this->rejectAndKeepLocal('throw');}
    public function testPartialReadbackRetainsSourcePair(): void {$this->rejectAndKeepLocal('partial');}
    public function testSelfConsistentButSubstitutedPackageIsRejected(): void {$this->rejectAndKeepLocal('substitute');}
}
