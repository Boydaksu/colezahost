<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
use Coleza\Domain\Backup\{BackupFileTree,PreUpdateBackupService,RestoreJournal};
use Coleza\Domain\OperationalMode\{OperationalMode,OperationalModeManager};
use Coleza\Domain\Updater\{ModuleCompatibilityChecker,PackageSignatureVerifier,StagedUpdateService,UpdatePackageManifest};
use Coleza\Foundation\Database\{Connection,Migrator};
use RuntimeException;

trait UpdatePublicationCases
{
    private string $publicationRoot;
    private array $publicationKeys;
    private function preparePublicationFixture(): void
    {
        $this->publicationRoot=sys_get_temp_dir().'/coleza_update_publication_'.bin2hex(random_bytes(12));
        mkdir($this->publicationRoot.'/package/files',0700,true);mkdir($this->publicationRoot.'/app/empty',0700,true);
        file_put_contents($this->publicationRoot.'/app/version.txt','old');file_put_contents($this->publicationRoot.'/app/config.txt',"private\0old");
        $this->installPublicationSchema();
        $this->publicationKeys=PackageSignatureVerifier::generateKeyPair();$this->publicationPackage();
    }
    private function installPublicationSchema(): void
    {
        (new \Coleza\Domain\Module\ModuleLifecycleService($this->db))->ensureTable(true);(new Migrator($this->db))->ensureMigrationsTable();OperationalModeManager::ensureSchema($this->db,true);
        $this->db->statement('CREATE TABLE old_records (id INT PRIMARY KEY, value VARCHAR(100))');$this->db->insert('old_records',['id'=>7,'value'=>'unchanged']);
    }
    private function publicationPackage(bool $migration=false,string $version='1.1.0'): void
    {
        $files=['version.txt'=>'new','nested/new.txt'=>'added'];$migrations=[];
        if($migration){$files['db/new_table.php']='<?php return new class implements \\Coleza\\Foundation\\Database\\MigrationInterface {public function up(\\Coleza\\Foundation\\Database\\Connection $db): void {$db->statement("CREATE TABLE new_records (id INT)"); $db->statement("UPDATE old_records SET value = ?", ["changed"]);} public function down(\\Coleza\\Foundation\\Database\\Connection $db): void {}};';$migrations=['db/new_table.php'];}
        $checks=[];foreach($files as $name=>$bytes){$path=$this->publicationRoot.'/package/files/'.$name;if(!is_dir(dirname($path))){mkdir(dirname($path),0700,true);}file_put_contents($path,$bytes);$checks[$name]=hash('sha256',$bytes);}
        $raw=json_encode((new UpdatePackageManifest($version,'1.0.0','publication tests',$checks,migrations:$migrations))->toArray(),JSON_THROW_ON_ERROR);
        file_put_contents($this->publicationRoot.'/package/manifest.json',$raw);file_put_contents($this->publicationRoot.'/package/manifest.sig',PackageSignatureVerifier::signPayload($raw,$this->publicationKeys['private']));
    }
    private function publicationReplaceFile(string $name,string $bytes): void
    {
        $path=$this->publicationRoot.'/package/files/'.$name;if(!is_dir(dirname($path))){mkdir(dirname($path),0700,true);}file_put_contents($path,$bytes);
        $data=json_decode(file_get_contents($this->publicationRoot.'/package/manifest.json'),true);$data['file_checksums'][$name]=hash('sha256',$bytes);$raw=json_encode($data,JSON_THROW_ON_ERROR);
        file_put_contents($this->publicationRoot.'/package/manifest.json',$raw);file_put_contents($this->publicationRoot.'/package/manifest.sig',PackageSignatureVerifier::signPayload($raw,$this->publicationKeys['private']));
    }
    private function publicationUpdater(?\Closure $checkpoint=null,string $version='1.0.0'): StagedUpdateService
    {
        return new StagedUpdateService(new PackageSignatureVerifier($this->publicationKeys['public']),new ModuleCompatibilityChecker($this->db),$version,$this->db,new PreUpdateBackupService($this->publicationRoot.'/backup',$this->db,$version),$checkpoint);
    }
    private function publicationApply(?\Closure $checkpoint=null): array {return $this->publicationUpdater($checkpoint)->applyValidatedUpdate($this->publicationRoot.'/package',$this->publicationRoot.'/app');}
    private function failPublicationAt(string $stage,bool $migration=true): array
    {
        $this->publicationPackage($migration);$fired=false;
        try{$this->publicationApply(function(string $name)use($stage,&$fired):void{if($name===$stage){$fired=true;throw new RuntimeException('injected '.$stage);}});self::fail('Failure checkpoint not reached.');}
        catch(RuntimeException $error){self::assertStringContainsString('injected '.$stage,$error->getMessage());}
        self::assertTrue($fired);$state=RestoreJournal::read(RestoreJournal::path($this->publicationRoot.'/app'));self::assertSame('failed',$state['state']);return $state;
    }
    private function assertOldPublicationRestored(): void
    {
        self::assertSame('old',file_get_contents($this->publicationRoot.'/app/version.txt'));self::assertSame("private\0old",file_get_contents($this->publicationRoot.'/app/config.txt'));
        self::assertDirectoryExists($this->publicationRoot.'/app/empty');self::assertFileDoesNotExist($this->publicationRoot.'/app/nested/new.txt');
        self::assertSame('unchanged',$this->db->selectOne('SELECT value FROM old_records WHERE id=7')['value']);self::assertSame([], (new Migrator($this->db))->getRanMigrations());
        self::assertNotContains('new_records',(new \Coleza\Domain\Backup\LogicalDatabaseDump())->tables($this->db));
        self::assertSame(OperationalMode::RECOVERY,(new OperationalModeManager($this->db))->getCurrentMode());
        self::assertSame('1.0.0',$this->db->selectOne('SELECT setting_value FROM system_settings WHERE setting_key=?',['system.core_version'])['setting_value']);
    }
    public function testWholeTreeIsPreparedBeforeEitherActiveFileChanges(): void
    {
        $fired=false;$result=$this->publicationApply(function(string $stage,array $context=[])use(&$fired):void{
            if($stage==='tree_prepared'){$fired=true;self::assertSame('old',file_get_contents($this->publicationRoot.'/app/version.txt'));self::assertFileDoesNotExist($this->publicationRoot.'/app/nested/new.txt');self::assertSame('new',file_get_contents($context['prepared_directory'].'/version.txt'));self::assertSame('added',file_get_contents($context['prepared_directory'].'/nested/new.txt'));}
        });
        self::assertTrue($fired);self::assertTrue($result['success']);self::assertSame('new',file_get_contents($this->publicationRoot.'/app/version.txt'));
        self::assertSame('old',file_get_contents($result['previous_directory'].'/version.txt'));self::assertDirectoryExists($this->publicationRoot.'/app/empty');
        self::assertSame('1.1.0',$this->db->selectOne('SELECT setting_value FROM system_settings WHERE setting_key=?',['system.core_version'])['setting_value']);
        self::assertSame('1.1.0',$this->publicationUpdater()->getCurrentCoreVersion());
    }
    public function testFreshUpdaterCannotReuseStaleConfiguredVersion(): void
    {
        $this->publicationApply();
        try{$this->publicationApply();self::fail('Stale version replay admitted.');}catch(RuntimeException $error){self::assertStringContainsString('version',$error->getMessage());}
        self::assertSame('new',file_get_contents($this->publicationRoot.'/app/version.txt'));
    }
    public function testFailureBeforeSwapRestoresDatabaseAndExactOldFiles(): void
    {
        $this->failPublicationAt('migrations_applied');$result=$this->publicationUpdater()->recoverFailedUpdate($this->publicationRoot.'/app');self::assertTrue($result['success']);$this->assertOldPublicationRestored();
    }
    public function testFailureBetweenRenamesCanRecoverMissingActiveDirectory(): void
    {
        $state=$this->failPublicationAt('old_tree_preserved');self::assertDirectoryDoesNotExist($this->publicationRoot.'/app');self::assertSame('old',file_get_contents($state['previous_directory'].'/version.txt'));
        self::assertTrue($this->publicationUpdater()->recoverFailedUpdate($this->publicationRoot.'/app')['success']);$this->assertOldPublicationRestored();
    }
    public function testPostPublicationFailureRemovesNewFilesAndNewTables(): void
    {
        $this->failPublicationAt('new_tree_published');self::assertSame('new',file_get_contents($this->publicationRoot.'/app/version.txt'));
        self::assertTrue($this->publicationUpdater()->recoverFailedUpdate($this->publicationRoot.'/app')['success']);$this->assertOldPublicationRestored();
    }
    public function testMigrationAddedTableAndMutatedRowsAreRestoredFromBackup(): void
    {
        $this->publicationPackage(true);$result=$this->publicationApply();self::assertSame(1,$result['migrations_run']);self::assertSame('changed',$this->db->selectOne('SELECT value FROM old_records')['value']);
        self::assertTrue($this->publicationUpdater()->recoverFailedUpdate($this->publicationRoot.'/app')['success']);$this->assertOldPublicationRestored();
    }
    public function testCorruptBackupIsDeniedBeforeRollbackDeletesAnything(): void
    {
        $state=$this->failPublicationAt('new_tree_published');file_put_contents($this->publicationRoot.'/backup/'.$state['backup_id'].'.zip','broken');
        try{$this->publicationUpdater()->recoverFailedUpdate($this->publicationRoot.'/app');self::fail('Corrupt rollback backup accepted.');}catch(RuntimeException $error){self::assertStringContainsString('backup',$error->getMessage());}
        self::assertSame('new',file_get_contents($this->publicationRoot.'/app/version.txt'));self::assertContains('new_records',(new \Coleza\Domain\Backup\LogicalDatabaseDump())->tables($this->db));
    }
    public function testExtraBackupSourcesDoNotBecomeApplicationFilesOnRollback(): void
    {
        file_put_contents($this->publicationRoot.'/extra','separate');
        $this->publicationUpdater()->applyValidatedUpdate($this->publicationRoot.'/package',$this->publicationRoot.'/app',backupExtraFiles:['outside'=>$this->publicationRoot.'/extra']);
        self::assertTrue($this->publicationUpdater()->recoverFailedUpdate($this->publicationRoot.'/app')['success']);self::assertDirectoryDoesNotExist($this->publicationRoot.'/app/_update_extra');$this->assertOldPublicationRestored();
    }
    public function testPreparedTreeTamperingIsRejectedBeforeSwap(): void
    {
        $fired=false;try{$this->publicationApply(function(string $stage,array $context=[])use(&$fired):void{if($stage==='tree_prepared'){$fired=true;file_put_contents($context['prepared_directory'].'/config.txt','tampered');}});self::fail('Prepared tree tampering accepted.');}
        catch(RuntimeException $error){self::assertStringContainsString('tree',$error->getMessage());}self::assertTrue($fired);self::assertSame('old',file_get_contents($this->publicationRoot.'/app/version.txt'));
    }
    public function testRollbackFailureIsVisibleAndRetryIsExplicit(): void
    {
        $this->failPublicationAt('new_tree_published');$fired=false;
        $restore=new \Coleza\Domain\Backup\RestoreWizardService($this->publicationRoot.'/restore',checkpoint:function(string $stage)use(&$fired):void{if($stage==='import_statement'){$fired=true;throw new RuntimeException('rollback import failed');}});
        try{$this->publicationUpdater()->recoverFailedUpdate($this->publicationRoot.'/app',$restore);self::fail('Failed rollback reported success.');}catch(RuntimeException $error){self::assertStringContainsString('rollback',$error->getMessage());}
        self::assertTrue($fired);self::assertSame('failed',RestoreJournal::read(RestoreJournal::path($this->publicationRoot.'/app'))['state']);
        self::assertTrue($this->publicationUpdater()->recoverFailedUpdate($this->publicationRoot.'/app')['success']);$this->assertOldPublicationRestored();
    }
    public function testRepeatedCompletedRollbackDoesNotEraseNewChanges(): void
    {
        $this->publicationApply();$this->publicationUpdater()->recoverFailedUpdate($this->publicationRoot.'/app');$this->db->statement('UPDATE old_records SET value=?',['after recovery']);
        $result=$this->publicationUpdater()->recoverFailedUpdate($this->publicationRoot.'/app');self::assertTrue($result['already_recovered']);self::assertSame('after recovery',$this->db->selectOne('SELECT value FROM old_records')['value']);
    }
    public function testRollbackCannotStealAnActiveUpdateLease(): void
    {
        $checked=false;$this->publicationApply(function(string $stage)use(&$checked):void{if($stage==='tree_prepared'){$checked=true;try{$this->publicationUpdater()->recoverFailedUpdate($this->publicationRoot.'/app');self::fail('Active update lock bypassed.');}catch(RuntimeException $error){self::assertStringContainsString('lock',$error->getMessage());}}});self::assertTrue($checked);
    }
    private function publicationWorkerConfig(): string
    {
        if($this->db->getDriverName()==='sqlite'){
            $this->db=new Connection(new \PDO('sqlite:'.$this->publicationRoot.'/database.sqlite'));$this->installPublicationSchema();$dsn='sqlite:'.$this->publicationRoot.'/database.sqlite';$database=null;
        }else{$dsn=getenv('COLEZA_TEST_MARIADB_DSN');$database=$this->db->getPdo()->query('SELECT DATABASE()')->fetchColumn();}
        $path=$this->publicationRoot.'/worker.json';file_put_contents($path,json_encode(['root'=>$this->publicationRoot,'public'=>$this->publicationKeys['public'],'dsn'=>$dsn,'database'=>$database],JSON_THROW_ON_ERROR));return $path;
    }
    private function publicationWorker(string $config,string $mode): array
    {
        $process=proc_open([PHP_BINARY,'-c',php_ini_loaded_file(),__DIR__.'/update-migration-worker.php',$config,$mode],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        self::assertIsResource($process);fclose($pipes[0]);
        try{$output=stream_get_contents($pipes[1]);self::assertSame('',stream_get_contents($pipes[2]));}finally{fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);}
        return [$exit,$output];
    }
    public static function publicationCrashStages(): array
    {
        return array_map(static fn(string $stage):array=>[$stage],['tree_prepared','migrations_applied','old_tree_preserved','new_tree_published','version_persisted']);
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('publicationCrashStages')]
    public function testNewProcessRecoversEachInterruptedPublicationBoundary(string $stage): void
    {
        $config=$this->publicationWorkerConfig();$this->publicationPackage(true);
        [$exit,$output]=$this->publicationWorker($config,'kill:'.$stage);self::assertSame(74,$exit);self::assertSame('',$output);
        [$exit,$output]=$this->publicationWorker($config,'recover');self::assertSame(0,$exit,$output);self::assertSame('recovered',$output);$this->assertOldPublicationRestored();
    }
    public function testNewProcessCanExplicitlyRetryInterruptedRollback(): void
    {
        $config=$this->publicationWorkerConfig();$this->publicationPackage(true);self::assertSame(74,$this->publicationWorker($config,'kill:new_tree_published')[0]);
        self::assertSame(75,$this->publicationWorker($config,'rollback-kill:import_statement')[0]);
        self::assertNotSame('complete',RestoreJournal::read(RestoreJournal::path($this->publicationRoot.'/app'))['state']);
        [$exit,$output]=$this->publicationWorker($config,'recover');self::assertSame(0,$exit,$output);$this->assertOldPublicationRestored();
    }
    public function testValidButReplacedBackupIsRejectedAfterIndependentReadback(): void
    {
        $state=$this->failPublicationAt('new_tree_published');
        $second=(new PreUpdateBackupService($this->publicationRoot.'/other-backup',$this->db))->createVerifiedPreUpdateBackup('1.1.0',['replacement'=>$this->publicationRoot.'/app']);
        $replacement=$second['manifest']->toArray();$replacement['backup_id']=$state['backup_id'];
        $fired=false;$updater=$this->publicationUpdater(function(string $stage)use($state,$second,$replacement,&$fired):void{if($stage==='recovery_backup_verified'){
            $fired=true;copy($second['archive_path'],$this->publicationRoot.'/backup/'.$state['backup_id'].'.zip');file_put_contents($this->publicationRoot.'/backup/'.$state['backup_id'].'.manifest.json',json_encode($replacement,JSON_THROW_ON_ERROR));
        }});
        try{$updater->recoverFailedUpdate($this->publicationRoot.'/app');self::fail('Replaced backup accepted.');}catch(RuntimeException $error){self::assertStringContainsString('backup',$error->getMessage());}
        self::assertTrue($fired);self::assertSame('changed',$this->db->selectOne('SELECT value FROM old_records')['value']);self::assertSame('new',file_get_contents($this->publicationRoot.'/app/version.txt'));
    }
    public function testForeignDatabaseCannotConsumeRecoveryJournal(): void
    {
        $this->failPublicationAt('new_tree_published');$wrong=new Connection(new \PDO('sqlite::memory:'));
        $updater=new StagedUpdateService(new PackageSignatureVerifier($this->publicationKeys['public']),new ModuleCompatibilityChecker($wrong),'1.0.0',$wrong,new PreUpdateBackupService($this->publicationRoot.'/backup',$wrong));
        try{$updater->recoverFailedUpdate($this->publicationRoot.'/app');self::fail('Foreign database recovered.');}catch(RuntimeException $error){self::assertStringContainsString('journal',$error->getMessage());}
        self::assertSame('changed',$this->db->selectOne('SELECT value FROM old_records')['value']);
    }
    public function testOrdinaryPhpSyntaxFailureCannotPublishEvenWithValidSignature(): void
    {
        $this->publicationReplaceFile('new.php','<?php function invalid syntax {');
        try{$this->publicationApply();self::fail('Invalid PHP published.');}catch(RuntimeException $error){self::assertStringContainsString('syntax',$error->getMessage());}
        self::assertSame('old',file_get_contents($this->publicationRoot.'/app/version.txt'));self::assertFileDoesNotExist($this->publicationRoot.'/app/new.php');self::assertDirectoryDoesNotExist($this->publicationRoot.'/backup');
    }
    public function testPayloadCannotCreateCaseCollisionWithPreservedTree(): void
    {
        $this->publicationReplaceFile('CONFIG.txt','collision');
        try{$this->publicationApply();self::fail('Non-portable combined tree admitted.');}catch(RuntimeException $error){self::assertStringContainsString('tree',$error->getMessage());}
        self::assertSame("private\0old",file_get_contents($this->publicationRoot.'/app/config.txt'));
    }
    public function testRealDatabaseHealthDeniesOrphanBeforeSwapAndRollbackRemovesItsTables(): void
    {
        $this->publicationPackage(true);$disable=$this->db->getDriverName()==='mysql'?'SET FOREIGN_KEY_CHECKS=0':'PRAGMA foreign_keys=OFF';$this->db->statement($disable);
        $this->publicationReplaceFile('db/new_table.php','<?php return new class implements \\Coleza\\Foundation\\Database\\MigrationInterface {public function up(\\Coleza\\Foundation\\Database\\Connection $db): void {$db->statement("CREATE TABLE extra_parent (id INT PRIMARY KEY)");$db->statement("CREATE TABLE extra_child (id INT PRIMARY KEY,parent_id INT,FOREIGN KEY(parent_id) REFERENCES extra_parent(id))");$db->statement("INSERT INTO extra_child VALUES(1,999)");}public function down(\\Coleza\\Foundation\\Database\\Connection $db): void {}};');
        try{$this->publicationApply();self::fail('Orphan passed release health.');}catch(RuntimeException $error){self::assertMatchesRegularExpression('/foreign|integrity|orphan/i',$error->getMessage());}
        self::assertSame('old',file_get_contents($this->publicationRoot.'/app/version.txt'));
        $this->db->statement('CREATE VIEW rollback_extra_view AS SELECT id FROM old_records');
        $trigger=$this->db->getDriverName()==='mysql'?'CREATE TRIGGER rollback_extra_trigger BEFORE INSERT ON old_records FOR EACH ROW SET NEW.value=NEW.value':'CREATE TRIGGER rollback_extra_trigger BEFORE INSERT ON old_records BEGIN SELECT 1; END';$this->db->statement($trigger);
        self::assertTrue($this->publicationUpdater()->recoverFailedUpdate($this->publicationRoot.'/app')['success']);
        (new \Coleza\Domain\Backup\LogicalDatabaseDump())->assertSupportedObjects($this->db);
        self::assertNotContains('extra_child',(new \Coleza\Domain\Backup\LogicalDatabaseDump())->tables($this->db));$this->assertOldPublicationRestored();
    }
    public function testRealPreviousDirectoryCollisionCannotPartlyPublishActiveFiles(): void
    {
        $this->publicationPackage(true);$fired=false;
        try{$this->publicationApply(function(string $stage,array $context=[])use(&$fired):void{if($stage==='tree_prepared'){$fired=true;file_put_contents($context['previous_directory'],'occupied');}});self::fail('Occupied previous path accepted.');}
        catch(RuntimeException $error){self::assertStringContainsString('preserve',$error->getMessage());}
        self::assertTrue($fired);self::assertSame('old',file_get_contents($this->publicationRoot.'/app/version.txt'));self::assertFileDoesNotExist($this->publicationRoot.'/app/nested/new.txt');
        self::assertTrue($this->publicationUpdater()->recoverFailedUpdate($this->publicationRoot.'/app')['success']);$this->assertOldPublicationRestored();
    }
    public function testRollbackCannotCommitOrReplaceCallerTransaction(): void
    {
        $this->failPublicationAt('new_tree_published');$pdo=$this->db->getPdo();$pdo->beginTransaction();
        try{try{$this->publicationUpdater()->recoverFailedUpdate($this->publicationRoot.'/app');self::fail('Rollback admitted caller transaction.');}catch(RuntimeException $error){self::assertStringContainsString('transaction',$error->getMessage());self::assertTrue($pdo->inTransaction());}}
        finally{$pdo->rollBack();}
        self::assertSame('new',file_get_contents($this->publicationRoot.'/app/version.txt'));self::assertTrue($this->publicationUpdater()->recoverFailedUpdate($this->publicationRoot.'/app')['success']);$this->assertOldPublicationRestored();
    }
}
