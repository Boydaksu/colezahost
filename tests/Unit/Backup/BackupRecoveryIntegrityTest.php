<?php
declare(strict_types=1);
namespace Coleza\Tests\Unit\Backup;

use Coleza\Domain\Backup\{BackupManifest, BackupScope, EnterpriseBackupService, RestoreWizardService};
use Coleza\Domain\Backup\Storage\LocalBackupStorageAdapter;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class BackupRecoveryIntegrityTest extends TestCase
{
    private string $root;
    protected function setUp(): void { $this->root=sys_get_temp_dir().'/coleza_recovery_'.bin2hex(random_bytes(8));mkdir($this->root,0700); }
    protected function tearDown(): void { $this->remove($this->root); }
    private function remove(string $path): void { if(is_link($path)||is_file($path)){unlink($path);return;}if(!is_dir($path)){return;}foreach(scandir($path)as$n){if($n!=='.'&&$n!=='..'){$this->remove($path.'/'.$n);}}rmdir($path); }
    private function database(): Connection { return new Connection(new PDO('sqlite::memory:')); }
    public static function unsafeNames(): array
    {
        return [['files/../../escape'],['files/C:/file'],['files\\..\\escape'],['/files/absolute'],['files/trailing. '],['files/a/./b'],['files/CON.txt'],['files//empty'],['files/name:ads']];
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeNames')]
    public function testUnsafeZipNamesAreRejectedDuringInspection(string $name): void
    {
        $storage=$this->crafted([$name=>'bytes'],[$name=>hash('sha256','bytes')]);$this->expectException(\RuntimeException::class);(new RestoreWizardService($this->root.'/stage'))->inspectBackup('crafted',$storage);
    }
    public function testZipSymlinkIsRejectedBeforeExtraction(): void
    {
        $storage=$this->crafted(['files/link'=>'../outside'],['files/link'=>hash('sha256','../outside')],['files/link'=>0120777<<16]);$this->expectException(\RuntimeException::class);(new RestoreWizardService($this->root.'/stage'))->inspectBackup('crafted',$storage);
    }
    public static function collisions(): array {return [[['files/A.txt'=>'a','files/a.txt'=>'b']],[['files/a'=>'a','files/a/b'=>'b']]];}
    #[\PHPUnit\Framework\Attributes\DataProvider('collisions')]
    public function testPortableZipCollisionsAreRejected(array $entries): void
    {
        $storage=$this->crafted($entries,array_map(static fn(string $v):string=>hash('sha256',$v),$entries));$this->expectException(\RuntimeException::class);(new RestoreWizardService($this->root.'/stage'))->inspectBackup('crafted',$storage);
    }
    public function testExplicitRelativeAliasesResolveDuplicateSourceBasenames(): void
    {
        mkdir($this->root.'/a');mkdir($this->root.'/b');file_put_contents($this->root.'/a/config.php','a');file_put_contents($this->root.'/b/config.php','b');$store=new LocalBackupStorageAdapter($this->root.'/store');
        $backup=(new EnterpriseBackupService($this->root.'/work'))->createBackup(BackupScope::FILES,$store,['first/config.php'=>$this->root.'/a/config.php','second/config.php'=>$this->root.'/b/config.php']);$result=(new RestoreWizardService($this->root.'/stage'))->executeRestore($backup['manifest']->getBackupId(),$store,$this->database(),$this->root.'/target');self::assertTrue($result->isSuccess(),$result->getErrorMessage()??'');self::assertSame('a',file_get_contents($this->root.'/target/first/config.php'));self::assertSame('b',file_get_contents($this->root.'/target/second/config.php'));
    }
    public function testSqliteIndexesAndDeletedAutoincrementHighWaterMarkArePreserved(): void
    {
        $source=$this->database();$source->statement('CREATE TABLE serials(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT)');$source->statement('CREATE UNIQUE INDEX unique_serial_name ON serials(name)');$source->statement("INSERT INTO serials(id,name) VALUES(100,'removed')");$source->statement('DELETE FROM serials');$store=new LocalBackupStorageAdapter($this->root.'/store');$backup=(new EnterpriseBackupService($this->root.'/work',$source))->createBackup(BackupScope::DATABASE,$store);$target=$this->database();
        $result=(new RestoreWizardService($this->root.'/stage'))->executeRestore($backup['manifest']->getBackupId(),$store,$target,$this->root.'/target');self::assertTrue($result->isSuccess(),$result->getErrorMessage()??'');self::assertSame(101,$target->insert('serials',['name'=>'new']));self::assertSame('unique_serial_name',$target->selectOne("PRAGMA index_list('serials')")['name']);
    }
    public function testInterruptedProcessLeavesDurableRecoveryGateAndOldFiles(): void
    {
        $source=$this->database();$source->statement('CREATE TABLE partial_table(id INTEGER PRIMARY KEY,value TEXT)');$source->insert('partial_table',['id'=>1,'value'=>'backup']);
        $store=new LocalBackupStorageAdapter($this->root.'/store');$backup=(new EnterpriseBackupService($this->root.'/work',$source))->createBackup(BackupScope::DATABASE,$store);
        mkdir($this->root.'/target',0700);file_put_contents($this->root.'/target/existing.txt','old');
        $job=['database'=>$this->root.'/target.sqlite','storage'=>$this->root.'/store','stage'=>$this->root.'/child-stage','target'=>$this->root.'/target','id'=>$backup['manifest']->getBackupId()];$pipes=[];
        $process=proc_open([PHP_BINARY,'-c',php_ini_loaded_file(),__DIR__.'/fixtures/interrupted-restore.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);self::assertIsResource($process);
        fwrite($pipes[0],json_encode($job,JSON_THROW_ON_ERROR));fclose($pipes[0]);self::assertSame('',stream_get_contents($pipes[1]));self::assertSame('',stream_get_contents($pipes[2]));fclose($pipes[1]);fclose($pipes[2]);self::assertSame(73,proc_close($process));
        $journal=\Coleza\Domain\Backup\RestoreJournal::path($this->root.'/target');self::assertSame('importing',\Coleza\Domain\Backup\RestoreJournal::read($journal)['state']);self::assertSame('old',file_get_contents($this->root.'/target/existing.txt'));
        $target=new Connection(new PDO('sqlite:'.$job['database']));$mode=new \Coleza\Domain\OperationalMode\OperationalModeManager($target,restoreJournalPath:$journal);self::assertSame('recovery',$mode->getCurrentMode()->value);
        try{$mode->assertActionPermitted(true,false,isSuperAdmin:true);self::fail('Recovery gate bypassed');}catch(\Coleza\Domain\OperationalMode\OperationModeRestrictionException){self::assertTrue(true);}
        $result=(new RestoreWizardService($this->root.'/retry'))->executeRestore($job['id'],$store,$target,$this->root.'/target');self::assertFalse($result->isSuccess());self::assertSame(0,$result->getDatabaseStatementsExecuted());self::assertTrue($store->exists($job['id']));
    }
    public function testExistingDatabaseAndCallerTransactionAreProtected(): void
    {
        $source=$this->database();$source->statement('CREATE TABLE live(id INT)');$source->statement('INSERT INTO live VALUES(1)');$store=new LocalBackupStorageAdapter($this->root.'/store');$backup=(new EnterpriseBackupService($this->root.'/work',$source))->createBackup(BackupScope::DATABASE,$store);
        $result=(new RestoreWizardService($this->root.'/stage'))->executeRestore($backup['manifest']->getBackupId(),$store,$source,$this->root.'/target');self::assertFalse($result->isSuccess());self::assertSame([['id'=>1]],$source->select('SELECT * FROM live'));
        $source->getPdo()->beginTransaction();try{$result=(new RestoreWizardService($this->root.'/stage'))->executeRestore($backup['manifest']->getBackupId(),$store,$source,$this->root.'/target');self::assertFalse($result->isSuccess());self::assertTrue($source->getPdo()->inTransaction());}finally{$source->getPdo()->rollBack();}
    }
    public function testMissingExternalTombstoneInventoryNeverBecomesEmptyHistory(): void
    {
        $source=$this->database();$source->statement('CREATE TABLE users(id INT PRIMARY KEY,name TEXT,email TEXT,status TEXT)');$source->insert('users',['id'=>1,'name'=>'PII','email'=>'private@example.test','status'=>'active']);
        $store=new LocalBackupStorageAdapter($this->root.'/store');$backup=(new EnterpriseBackupService($this->root.'/work',$source))->createBackup(BackupScope::DATABASE,$store);$target=$this->database();
        $result=(new RestoreWizardService($this->root.'/stage'))->executeRestore($backup['manifest']->getBackupId(),$store,$target,$this->root.'/target');self::assertFalse($result->isSuccess());self::assertStringContainsString('out-of-band',$result->getErrorMessage());self::assertSame(0,$result->getDatabaseStatementsExecuted());self::assertSame([], $target->select("SELECT name FROM sqlite_master WHERE name='users'"));
    }
    public function testRecursiveDirectoryRoundTripKeepsDistinctNamesAndEmptyDirectories(): void
    {
        mkdir($this->root.'/uploads/a',0700,true);mkdir($this->root.'/uploads/b/boş',0700,true);
        file_put_contents($this->root.'/uploads/a/same.bin',"a\0\xff");file_put_contents($this->root.'/uploads/b/same.bin',"İstanbul\n;b");
        $storage=new LocalBackupStorageAdapter($this->root.'/store');$error=null;
        try{$backup=(new EnterpriseBackupService($this->root.'/work'))->createBackup(BackupScope::FILES,$storage,[$this->root.'/uploads']);
            $result=(new RestoreWizardService($this->root.'/stage'))->executeRestore($backup['manifest']->getBackupId(),$storage,$this->database(),$this->root.'/target');
            self::assertTrue($result->isSuccess(),$result->getErrorMessage()??'');self::assertSame("a\0\xff",file_get_contents($this->root.'/target/uploads/a/same.bin'));self::assertSame("İstanbul\n;b",file_get_contents($this->root.'/target/uploads/b/same.bin'));self::assertDirectoryExists($this->root.'/target/uploads/b/boş');
        }catch(\RuntimeException $e){$error=$e->getMessage();}self::assertNull($error,$error??'');
    }
    public function testSqliteBinaryAndMultilineTextSurviveBackupRestore(): void
    {
        $source=$this->database();$source->statement('CREATE TABLE payloads (id INTEGER PRIMARY KEY, body BLOB, message TEXT)');
        $statement=$source->getPdo()->prepare('INSERT INTO payloads VALUES (1,?,?)');$statement->bindValue(1,"\0\xff\x01'binary",PDO::PARAM_LOB);$statement->bindValue(2,"line;\n-- keep this\nDPO's \\ İstanbul\0tail");$statement->execute();
        $storage=new LocalBackupStorageAdapter($this->root.'/store');$backup=(new EnterpriseBackupService($this->root.'/work',$source))->createBackup(BackupScope::DATABASE,$storage);$target=$this->database();
        $result=(new RestoreWizardService($this->root.'/stage'))->executeRestore($backup['manifest']->getBackupId(),$storage,$target,$this->root.'/target');
        self::assertTrue($result->isSuccess(),$result->getErrorMessage()??'');self::assertSame($source->select('SELECT *,typeof(body),typeof(message) FROM payloads'),$target->select('SELECT *,typeof(body),typeof(message) FROM payloads'));
    }
    public function testWrongEntryChecksumBlocksDatabaseImport(): void
    {
        $storage=$this->crafted(['database.sql'=>'CREATE TABLE injected(id INT);'],['database.sql'=>str_repeat('a',64)]);
        $target=$this->database();$result=(new RestoreWizardService($this->root.'/stage'))->executeRestore('crafted',$storage,$target,$this->root.'/target');
        self::assertFalse($result->isSuccess());self::assertSame([], $target->select("SELECT name FROM sqlite_master WHERE name='injected'"));
    }
    public function testUnlistedArchiveEntryBlocksInspection(): void
    {
        $storage=$this->crafted(['files/asset.txt'=>'extra'],[]);$failed=false;
        try{(new RestoreWizardService($this->root.'/stage'))->inspectBackup('crafted',$storage);}catch(\RuntimeException){$failed=true;}
        self::assertTrue($failed,'Manifest omitted archive payload but inspection accepted it');
    }
    public function testCommentOnlyLegacyDatabaseCannotBecomeSuccessfulRestore(): void
    {
        $sql="-- legacy comment-only dump\n";$storage=$this->crafted(['database.sql'=>$sql],['database.sql'=>hash('sha256',$sql)]);$target=$this->database();$result=(new RestoreWizardService($this->root.'/stage'))->executeRestore('crafted',$storage,$target,$this->root.'/target');
        self::assertFalse($result->isSuccess());self::assertSame(0,$result->getDatabaseStatementsExecuted());self::assertStringContainsString('inventory',$result->getErrorMessage());self::assertSame([], $target->select("SELECT name FROM sqlite_master WHERE type='table'"));
    }
    public function testUnsafeArchivePathCannotReachTarget(): void
    {
        $name='files/../outside.txt';$storage=$this->crafted([$name=>'unsafe'],[$name=>hash('sha256','unsafe')]);
        $result=(new RestoreWizardService($this->root.'/stage'))->executeRestore('crafted',$storage,$this->database(),$this->root.'/target');
        self::assertFalse($result->isSuccess());self::assertFileDoesNotExist($this->root.'/outside.txt');
    }
    private function crafted(array $entries,array $contained,array $attributes=[]): LocalBackupStorageAdapter
    {
        $zip=new \ZipArchive();$archive=$this->root.'/crafted.zip';$zip->open($archive,\ZipArchive::CREATE);foreach($entries as$n=>$data){$zip->addFromString($n,$data);if(isset($attributes[$n])){$zip->setExternalAttributesName($n,\ZipArchive::OPSYS_UNIX,$attributes[$n]);}}$zip->close();
        $manifest=new BackupManifest('crafted',isset($entries['database.sql'])?'full':'files','1.0',filesize($archive),hash_file('sha256',$archive),date('c'),$contained);$path=$this->root.'/crafted.json';file_put_contents($path,json_encode($manifest->toArray(),JSON_THROW_ON_ERROR));
        $store=new LocalBackupStorageAdapter($this->root.'/store');$store->store('crafted',$archive,$path);return $store;
    }
}
