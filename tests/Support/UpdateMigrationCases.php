<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;

use Coleza\Domain\Backup\{BackupFileTree,PreUpdateBackupService,RestoreJournal};
use Coleza\Domain\Updater\{ModuleCompatibilityChecker,PackageSignatureVerifier,StagedUpdateService,UpdatePackageManifest};
use Coleza\Domain\OperationalMode\{OperationalMode,OperationalModeManager};
use Coleza\Foundation\Database\Migrator;
use RuntimeException;

trait UpdateMigrationCases
{
    private string $migrationRoot;
    private array $migrationKeys;
    private function prepareMigrationFixture(): void
    {
        $this->migrationRoot=sys_get_temp_dir().'/coleza_update_migrations_'.bin2hex(random_bytes(12));
        mkdir($this->migrationRoot.'/package/files',0700,true);mkdir($this->migrationRoot.'/app');
        file_put_contents($this->migrationRoot.'/app/version.txt','old');
        $this->installMigrationFixtureSchema();
        $this->migrationKeys=PackageSignatureVerifier::generateKeyPair();
    }
    private function installMigrationFixtureSchema(): void
    {
        (new \Coleza\Domain\Module\ModuleLifecycleService($this->db))->ensureTable(true);
        (new Migrator($this->db))->ensureMigrationsTable();
        OperationalModeManager::ensureSchema($this->db,true);
    }
    private function migrationCode(string $body): string
    {
        return '<?php return new class implements \\Coleza\\Foundation\\Database\\MigrationInterface { public function up(\\Coleza\\Foundation\\Database\\Connection $db): void { '.$body.' } public function down(\\Coleza\\Foundation\\Database\\Connection $db): void {} };';
    }
    private function migrationPackage(array $scripts,?array $order=null,string $version='1.1.0'): string
    {
        $files=['version.txt'=>hash('sha256','new')];file_put_contents($this->migrationRoot.'/package/files/version.txt','new');
        foreach($scripts as $path=>$code){$file=$this->migrationRoot.'/package/files/'.$path;if(!is_dir(dirname($file))){mkdir(dirname($file),0700,true);}file_put_contents($file,$code);$files[$path]=hash('sha256',$code);}
        $raw=json_encode((new UpdatePackageManifest($version,'1.0.0','migration tests',$files,migrations:$order??array_keys($scripts)))->toArray(),JSON_THROW_ON_ERROR);
        file_put_contents($this->migrationRoot.'/package/manifest.json',$raw);
        file_put_contents($this->migrationRoot.'/package/manifest.sig',PackageSignatureVerifier::signPayload($raw,$this->migrationKeys['private']));
        return $this->migrationRoot.'/package';
    }
    private function migrationUpdater(?\Closure $checkpoint=null,string $version='1.0.0'): StagedUpdateService
    {
        return new StagedUpdateService(new PackageSignatureVerifier($this->migrationKeys['public']),new ModuleCompatibilityChecker($this->db),$version,$this->db,new PreUpdateBackupService($this->migrationRoot.'/backup',$this->db,$version),$checkpoint);
    }
    private function applyMigrationPackage(string $version='1.0.0'): array {return $this->migrationUpdater(version:$version)->applyValidatedUpdate($this->migrationRoot.'/package',$this->migrationRoot.'/app');}
    private function assertMigrationRejected(string $contains,string $version='1.0.0'): void
    {
        try{$this->applyMigrationPackage($version);self::fail('Unsafe migration accepted.');}
        catch(RuntimeException $error){self::assertStringContainsString($contains,$error->getMessage());}
    }
    public function testSignedOrderRunsRealSqlAndCountsOnlyNewMigrations(): void
    {
        $this->migrationPackage([
            'db/z_create.php'=>$this->migrationCode('$db->statement("CREATE TABLE update_probe (id INT PRIMARY KEY)");'),
            'db/a_insert.php'=>$this->migrationCode('$db->statement("INSERT INTO update_probe VALUES (7)");'),
        ]);
        $result=$this->applyMigrationPackage();self::assertSame(2,$result['migrations_run']);
        $backup=json_decode(file_get_contents($this->migrationRoot.'/backup/'.$result['backup_id'].'.manifest.json'),true,flags:JSON_THROW_ON_ERROR);
        self::assertSame(0,$backup['metadata']['database']['tables']['migrations']['rows']);
        self::assertArrayNotHasKey('update_probe',$backup['metadata']['database']['tables']);
        self::assertSame(7,(int)$this->db->selectOne('SELECT id FROM update_probe')['id']);
        self::assertSame(['z_create','a_insert'],(new Migrator($this->db))->getRanMigrations());
        self::assertSame('new',file_get_contents($this->migrationRoot.'/app/version.txt'));
        self::assertSame(OperationalMode::RECOVERY,(new OperationalModeManager($this->db))->getCurrentMode());
        self::assertSame('complete',RestoreJournal::read(RestoreJournal::path($this->migrationRoot.'/app'))['state']);
        $raw=json_decode(file_get_contents($this->migrationRoot.'/package/manifest.json'),true);$raw['version']='1.2.0';$raw=json_encode($raw,JSON_THROW_ON_ERROR);
        file_put_contents($this->migrationRoot.'/package/manifest.json',$raw);file_put_contents($this->migrationRoot.'/package/manifest.sig',PackageSignatureVerifier::signPayload($raw,$this->migrationKeys['private']));
        self::assertSame(0,$this->applyMigrationPackage('1.1.0')['migrations_run']);
        self::assertCount(1,$this->db->select('SELECT * FROM update_probe'));
    }
    public function testChangedPreviouslyAppliedBytesAreDenied(): void
    {
        $this->migrationPackage(['db/once.php'=>$this->migrationCode('$db->statement("CREATE TABLE update_probe (id INT)");')]);$this->applyMigrationPackage();
        $this->migrationPackage(['db/once.php'=>$this->migrationCode('throw new \\RuntimeException("must not execute");')],version:'1.2.0');
        $this->assertMigrationRejected('identity','1.1.0');
        self::assertCount(1,(new Migrator($this->db))->getRanMigrations());
    }
    public function testUnboundExistingLedgerNameIsDenied(): void
    {
        $this->db->insert('migrations',['migration'=>'once','batch'=>1]);
        $this->migrationPackage(['db/once.php'=>$this->migrationCode('throw new \\RuntimeException("must not execute");')]);
        $this->assertMigrationRejected('identity');self::assertSame('old',file_get_contents($this->migrationRoot.'/app/version.txt'));
    }
    public function testDuplicateBasenamesAreRejectedBeforeAnyExecution(): void
    {
        $this->migrationPackage(['a/once.php'=>$this->migrationCode(''),'b/once.php'=>$this->migrationCode('')]);
        $this->assertMigrationRejected('Duplicate');self::assertSame([], (new Migrator($this->db))->getRanMigrations());
    }
    public function testSyntaxErrorInLaterMigrationPreventsFirstMigration(): void
    {
        $this->migrationPackage(['db/first.php'=>$this->migrationCode('$db->statement("CREATE TABLE update_probe (id INT)");'),'db/bad.php'=>'<?php return new class { broken syntax };']);
        $this->assertMigrationRejected('syntax');self::assertSame([], (new Migrator($this->db))->getRanMigrations());
        self::assertSame('old',file_get_contents($this->migrationRoot.'/app/version.txt'));
    }
    public function testPartialDdlFailureRemainsJournaledAndBlocksFilesOnlyRetry(): void
    {
        $this->migrationPackage(['db/partial.php'=>$this->migrationCode('$db->statement("CREATE TABLE update_probe (id INT)"); throw new \\RuntimeException("deliberate partial failure");')]);
        $this->assertMigrationRejected('deliberate partial failure');
        self::assertSame([], (new Migrator($this->db))->getRanMigrations());self::assertSame('old',file_get_contents($this->migrationRoot.'/app/version.txt'));
        self::assertSame('failed',RestoreJournal::read(RestoreJournal::path($this->migrationRoot.'/app'))['state']);
        $exists=$this->db->getDriverName()==='mysql'?$this->db->selectOne('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',['update_probe']):$this->db->selectOne("SELECT 1 FROM sqlite_master WHERE name='update_probe'");
        self::assertSame($this->db->getDriverName()==='mysql',$exists!==null);
        $guard=new OperationalModeManager($this->db,restoreJournalPath:RestoreJournal::path($this->migrationRoot.'/app'));
        try{$guard->assertActionPermitted(true,false,isSuperAdmin:true);self::fail('Recovery bypassed.');}catch(\Coleza\Domain\OperationalMode\OperationModeRestrictionException){self::assertSame(OperationalMode::RECOVERY,$guard->getCurrentMode());}
        unlink($this->migrationRoot.'/package/files/db/partial.php');rmdir($this->migrationRoot.'/package/files/db');$this->migrationPackage([]);
        $this->assertMigrationRejected('recovery');self::assertSame('old',file_get_contents($this->migrationRoot.'/app/version.txt'));
    }
    public function testRawCallerTransactionIsPreserved(): void
    {
        $this->migrationPackage(['db/once.php'=>$this->migrationCode('')]);$pdo=$this->db->getPdo();$pdo->beginTransaction();
        try{$this->assertMigrationRejected('transaction');self::assertTrue($pdo->inTransaction());}finally{$pdo->rollBack();}
        self::assertSame([], (new Migrator($this->db))->getRanMigrations());
    }
    public function testDeletedLedgerCannotReuseAnAppliedReceipt(): void
    {
        $this->migrationPackage(['db/once.php'=>$this->migrationCode('')]);$this->applyMigrationPackage();$this->db->statement('DELETE FROM migrations');
        $this->migrationPackage(['db/once.php'=>$this->migrationCode('')],version:'1.2.0');$this->assertMigrationRejected('identity','1.1.0');
    }
    public function testNestedUpdateCannotAcquireTargetLock(): void
    {
        $this->migrationPackage(['db/once.php'=>$this->migrationCode('')]);$checked=false;
        $updater=$this->migrationUpdater(function(string $stage)use(&$checked):void{if($stage==='package_frozen'){$checked=true;$this->assertMigrationRejected('lock');}});
        self::assertSame(1,$updater->applyValidatedUpdate($this->migrationRoot.'/package',$this->migrationRoot.'/app')['migrations_run']);self::assertTrue($checked);
    }
    public function testFrozenMigrationBytesSurviveSourceMutation(): void
    {
        $this->migrationPackage(['db/once.php'=>$this->migrationCode('$db->statement("CREATE TABLE update_probe (id INT)");')]);$checked=false;
        $updater=$this->migrationUpdater(function(string $stage)use(&$checked):void{if($stage==='package_frozen'){$checked=true;file_put_contents($this->migrationRoot.'/package/files/db/once.php','<?php throw new RuntimeException("untrusted replacement");');}});
        self::assertSame(1,$updater->applyValidatedUpdate($this->migrationRoot.'/package',$this->migrationRoot.'/app')['migrations_run']);self::assertTrue($checked);
        self::assertSame([], $this->db->select('SELECT * FROM update_probe'));
    }
    public function testSignedPhpOutsideMigrationListIsNeverExecuted(): void
    {
        $this->migrationPackage(['db/unlisted.php'=>'<?php throw new RuntimeException("not a migration");'],[]);
        self::assertSame(0,$this->applyMigrationPackage()['migrations_run']);self::assertSame([], (new Migrator($this->db))->getRanMigrations());
    }
    public function testPartialFailureCannotBeBypassedWithAnotherTargetAndBackupStore(): void
    {
        $this->migrationPackage(['db/partial.php'=>$this->migrationCode('throw new \\RuntimeException("partial failure");')]);$this->assertMigrationRejected('partial failure');
        mkdir($this->migrationRoot.'/another-app');
        $other=new StagedUpdateService(new PackageSignatureVerifier($this->migrationKeys['public']),new ModuleCompatibilityChecker($this->db),'1.0.0',$this->db,new PreUpdateBackupService($this->migrationRoot.'/another-backup',$this->db));
        try{$other->applyValidatedUpdate($this->migrationRoot.'/package',$this->migrationRoot.'/another-app');self::fail('Cross-target recovery bypassed.');}
        catch(RuntimeException $error){self::assertStringContainsString('recovery',$error->getMessage());}
        self::assertSame([], (new Migrator($this->db))->getRanMigrations());
    }
    public function testWrongMigrationReturnTypeCannotBeRecordedOrPublished(): void
    {
        $this->migrationPackage(['db/wrong.php'=>'<?php return new stdClass();']);$this->assertMigrationRejected('MigrationInterface');
        self::assertSame([], (new Migrator($this->db))->getRanMigrations());self::assertSame('old',file_get_contents($this->migrationRoot.'/app/version.txt'));
        self::assertSame('failed',RestoreJournal::read(RestoreJournal::path($this->migrationRoot.'/app'))['state']);
    }
    public function testTopLevelPhpFailureIsJournaledBeforeRequire(): void
    {
        $this->migrationPackage(['db/top.php'=>'<?php throw new RuntimeException("top level failure");']);$this->assertMigrationRejected('top level failure');
        self::assertSame('failed',RestoreJournal::read(RestoreJournal::path($this->migrationRoot.'/app'))['state']);
        $receipt=glob($this->migrationRoot.'/backup/.update-migrations/*/*.json');
        self::assertCount(1,$receipt);self::assertSame('importing',RestoreJournal::read($receipt[0])['state']);
    }
    public function testLaterFailureKeepsOnlyActuallyCompletedLedgerEntries(): void
    {
        $this->migrationPackage(['db/first.php'=>$this->migrationCode('$db->statement("CREATE TABLE update_probe (id INT)");'),'db/fail.php'=>$this->migrationCode('throw new \\RuntimeException("second failed");')]);
        $this->assertMigrationRejected('second failed');self::assertSame(['first'],(new Migrator($this->db))->getRanMigrations());
        $state=RestoreJournal::read(RestoreJournal::path($this->migrationRoot.'/app'));self::assertSame(1,$state['migrations_run']);self::assertSame('failed',$state['state']);
        self::assertSame('old',file_get_contents($this->migrationRoot.'/app/version.txt'));
    }
    private function workerConfig(): string
    {
        if($this->db->getDriverName()==='sqlite'){
            $this->db=new \Coleza\Foundation\Database\Connection(new \PDO('sqlite:'.$this->migrationRoot.'/persistent.sqlite'));
            $this->installMigrationFixtureSchema();$dsn='sqlite:'.$this->migrationRoot.'/persistent.sqlite';$database=null;
        }else{$dsn=getenv('COLEZA_TEST_MARIADB_DSN');$database=$this->db->getPdo()->query('SELECT DATABASE()')->fetchColumn();}
        $path=$this->migrationRoot.'/worker.json';file_put_contents($path,json_encode(['root'=>$this->migrationRoot,'public'=>$this->migrationKeys['public'],'dsn'=>$dsn,'database'=>$database],JSON_THROW_ON_ERROR));return $path;
    }
    private function startMigrationWorker(string $config,string $mode): array
    {
        $process=proc_open([PHP_BINARY,'-c',php_ini_loaded_file(),__DIR__.'/update-migration-worker.php',$config,$mode],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        self::assertIsResource($process);fclose($pipes[0]);return [$process,$pipes];
    }
    public function testProcessDeathLeavesDurableIntentAndDeniesNewProcessRetry(): void
    {
        $config=$this->workerConfig();$this->migrationPackage(['db/crash.php'=>$this->migrationCode('$db->statement("CREATE TABLE update_probe (id INT)"); exit(73);')]);
        [$process,$pipes]=$this->startMigrationWorker($config,'apply');
        try{self::assertSame('',stream_get_contents($pipes[1]));self::assertSame('',stream_get_contents($pipes[2]));}finally{fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);}
        self::assertSame(73,$exit);self::assertSame('importing',RestoreJournal::read(RestoreJournal::path($this->migrationRoot.'/app'))['state']);
        self::assertSame([], (new Migrator($this->db))->getRanMigrations());self::assertSame('old',file_get_contents($this->migrationRoot.'/app/version.txt'));
        [$process,$pipes]=$this->startMigrationWorker($config,'apply');
        try{$output=stream_get_contents($pipes[1]);self::assertSame('',stream_get_contents($pipes[2]));}finally{fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);}
        self::assertSame(1,$exit);self::assertStringContainsString('recovery',$output);
    }
    public function testTwoProcessesCannotApplyTheSameTargetConcurrently(): void
    {
        $config=$this->workerConfig();$this->migrationPackage(['db/once.php'=>$this->migrationCode('$db->statement("CREATE TABLE update_probe (id INT)");')]);
        [$process,$pipes]=$this->startMigrationWorker($config,'hold');
        try{
            $deadline=microtime(true)+15;while(!file_exists($this->migrationRoot.'/ready')&&microtime(true)<$deadline){usleep(10000);}
            self::assertFileExists($this->migrationRoot.'/ready');$this->assertMigrationRejected('lock');
            file_put_contents($this->migrationRoot.'/release','go');
            self::assertSame('1',trim(stream_get_contents($pipes[1])));self::assertSame('',stream_get_contents($pipes[2]));
        }finally{file_put_contents($this->migrationRoot.'/release','go');fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);}
        self::assertSame(0,$exit);self::assertSame(['once'],(new Migrator($this->db))->getRanMigrations());
    }
    public function testManagedCallerTransactionIsPreserved(): void
    {
        $this->migrationPackage(['db/once.php'=>$this->migrationCode('')]);
        $this->db->transaction(function():void{$this->assertMigrationRejected('transaction');self::assertTrue($this->db->inTransaction());});
        self::assertSame([], (new Migrator($this->db))->getRanMigrations());
    }
    public function testCaseVariantLedgerNamesCannotRunTwice(): void
    {
        $this->migrationPackage(['a/Once.php'=>$this->migrationCode(''),'b/once.php'=>$this->migrationCode('')]);
        $this->assertMigrationRejected('Duplicate');self::assertSame([], (new Migrator($this->db))->getRanMigrations());
    }
    public function testDatabaseRecoveryGuardAlsoBlocksFilesOnlyUpdate(): void
    {
        $this->db->insert('system_settings',['setting_key'=>'system.update_migration_guard','setting_value'=>'{"state":"importing"}']);
        $this->migrationPackage([]);$this->assertMigrationRejected('recovery');
        self::assertSame('old',file_get_contents($this->migrationRoot.'/app/version.txt'));
    }
    public function testIncompleteInstalledSchemaIsNotBootstrappedByUpdate(): void
    {
        $this->db->statement('DROP TABLE migrations');$this->migrationPackage(['db/once.php'=>$this->migrationCode('')]);
        $this->assertMigrationRejected('installed');self::assertSame('old',file_get_contents($this->migrationRoot.'/app/version.txt'));
        self::assertFalse(\Coleza\Foundation\Database\PdoSchema::useInstalledSchema($this->db,['migrations'=>['migration']]));
        self::assertSame([],glob($this->migrationRoot.'/backup/*.zip'));
    }
    public function testModuleCompatibilityIsRecheckedAfterSuccessfulSqlBeforePublication(): void
    {
        $this->db->insert('installed_modules',['module_id'=>'probe','name'=>'probe','version'=>'1.0.0','type'=>'addon','is_enabled'=>1,'manifest'=>'{"min_core_version":"1.0.0"}']);
        $this->migrationPackage(['db/module.php'=>$this->migrationCode('$db->statement("UPDATE installed_modules SET manifest = ? WHERE module_id = ?", [\'{"min_core_version":"9.0.0"}\', "probe"]);')]);
        $this->assertMigrationRejected('compatibility');self::assertSame(['module'],(new Migrator($this->db))->getRanMigrations());
        self::assertSame('old',file_get_contents($this->migrationRoot.'/app/version.txt'));self::assertSame('failed',RestoreJournal::read(RestoreJournal::path($this->migrationRoot.'/app'))['state']);
    }
}
