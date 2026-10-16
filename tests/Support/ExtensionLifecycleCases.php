<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
use Coleza\Domain\Module\{ExtensionInstaller,ExtensionMigrationPlan,ModuleLifecycleService};
use Coleza\Domain\Backup\{BackupFileTree,PreUpdateBackupService,RestoreJournal};
use Coleza\Domain\Updater\{PackageSignatureVerifier,UpdatePackageManifest};
use Coleza\Domain\OperationalMode\OperationalModeManager;
use RuntimeException;

trait ExtensionLifecycleCases
{
    private string $extensionRoot;
    private array $extensionKeys;
    private array $descriptor;
    private function prepareExtensionFixture(): void
    {
        $this->extensionRoot=sys_get_temp_dir().'/coleza_extension_'.bin2hex(random_bytes(12));foreach(['package/files/migrations','modules','public'] as $dir){mkdir($this->extensionRoot.'/'.$dir,0700,true);}
        $this->installExtensionSchema();$this->extensionKeys=PackageSignatureVerifier::generateKeyPair();
        $this->descriptor=['id'=>'demo','name'=>'Demo İstanbul','version'=>'1.0.0','type'=>'integration','min_core_version'=>'1.0.0','max_core_version'=>'1.9.0','extension_api_version'=>'1.0.0',
            'capabilities'=>['demo.records'],'permissions'=>['database:read','database:write','storage:read','storage:write','settings:read','settings:write','routes:register'],
            'dependencies'=>[],'migration_files'=>['migrations/001.json']];$this->extensionPackage();
    }
    private function installExtensionSchema(): void
    {
        (new ModuleLifecycleService($this->db))->ensureTable(true);OperationalModeManager::ensureSchema($this->db,true);(new \Coleza\Domain\Identity\Audit\AuditLogger($this->db))->ensureTable();
    }
    private function extensionPackage(?array $migration=null): void
    {
        $files=['extension.json'=>json_encode($this->descriptor,JSON_THROW_ON_ERROR),'module.php'=>'<?php throw new RuntimeException("Package PHP must not be implicitly booted");',
            'migrations/001.json'=>json_encode($migration??['operations'=>[['op'=>'create_table','table'=>'records','columns'=>['id'=>['type'=>'integer','primary'=>true],'value'=>['type'=>'string','length'=>100]]]]],JSON_THROW_ON_ERROR)];
        $checks=[];foreach($files as $name=>$bytes){file_put_contents($this->extensionRoot.'/package/files/'.$name,$bytes);$checks[$name]=hash('sha256',$bytes);}
        $raw=json_encode((new UpdatePackageManifest($this->descriptor['version'],$this->descriptor['min_core_version'],'extension fixture',$checks,$this->descriptor['dependencies']))->toArray(),JSON_THROW_ON_ERROR);
        file_put_contents($this->extensionRoot.'/package/manifest.json',$raw);file_put_contents($this->extensionRoot.'/package/manifest.sig',PackageSignatureVerifier::signPayload($raw,$this->extensionKeys['private']));
    }
    private function extensionInstaller(?\Closure $health=null,?\Closure $checkpoint=null,?\Closure $network=null,?\Coleza\Domain\Vault\VaultService $vault=null): ExtensionInstaller
    {
        return new ExtensionInstaller($this->db,new PackageSignatureVerifier($this->extensionKeys['public']),$this->extensionRoot.'/modules',$this->extensionRoot.'/public',new PreUpdateBackupService($this->extensionRoot.'/backup',$this->db),adapterHealth:$health??static fn(string $id,$context):bool=>$id==='demo'&&$context->count('records')===0,vault:$vault,network:$network,checkpoint:$checkpoint);
    }
    private function extensionInstall(?ExtensionInstaller $installer=null,bool $enable=true): array
    {
        $installer??=$this->extensionInstaller();$preview=$installer->preview($this->extensionRoot.'/package');return $installer->install($this->extensionRoot.'/package',$preview['digest'],$preview['permissions'],$enable);
    }
    public function testPreviewHasNoDdlNoPhpExecutionAndExactNamespacedSql(): void
    {
        $this->db->enableQueryLog();$this->db->flushQueryLog();$preview=$this->extensionInstaller()->preview($this->extensionRoot.'/package');
        self::assertCount(1,$preview['migrations']);self::assertStringContainsString('CREATE TABLE `'.ExtensionMigrationPlan::prefix('demo').'records`',$preview['migrations'][0]['sql'][0]);
        foreach($this->db->getQueryLog() as $query){self::assertMatchesRegularExpression('/^SELECT|^PRAGMA/i',$query['query']);}
        self::assertNull($this->db->selectOne('SELECT id FROM installed_modules'));self::assertDirectoryDoesNotExist($this->extensionRoot.'/backup');
    }
    public function testInstallRunsRealMigrationThenHealthThenEnableAndDisableRetainsData(): void
    {
        $installer=$this->extensionInstaller();$result=$this->extensionInstall($installer);self::assertTrue($result['enabled']);self::assertSame(1,$result['migrations_run']);
        $context=$installer->context('demo');$context->insert('records',['id'=>7,'value'=>'İstanbul']);self::assertSame('İstanbul',$context->rows('records')[0]['value']);
        $context->writeFile('private/data.txt','retained');$context->setSetting('color','blue');self::assertSame('blue',$context->getSetting('color'));
        (new ModuleLifecycleService($this->db))->disable('demo');
        try{$context->count('records');self::fail('Captured context survived disable.');}catch(RuntimeException){self::assertSame(1,(int)$this->db->getPdo()->query('SELECT COUNT(*) FROM `'.ExtensionMigrationPlan::prefix('demo').'records`')->fetchColumn());}
        self::assertSame('retained',file_get_contents($this->extensionRoot.'/extension-data/demo/private/data.txt'));self::assertFileExists($this->extensionRoot.'/modules/demo/module.php');
    }
    public function testMissingPermissionGrantCannotInstall(): void
    {
        $installer=$this->extensionInstaller();$preview=$installer->preview($this->extensionRoot.'/package');
        try{$installer->install($this->extensionRoot.'/package',$preview['digest'],[]);self::fail('Permission approval bypassed.');}catch(RuntimeException $error){self::assertStringContainsString('approval',$error->getMessage());}
        self::assertNull($this->db->selectOne('SELECT id FROM installed_modules'));self::assertDirectoryDoesNotExist($this->extensionRoot.'/backup');
    }
    public function testChangedPackageCannotReuseOldPreviewApproval(): void
    {
        $installer=$this->extensionInstaller();$old=$installer->preview($this->extensionRoot.'/package');$this->descriptor['name']='Changed';$this->extensionPackage();
        try{$installer->install($this->extensionRoot.'/package',$old['digest'],$old['permissions']);self::fail('Stale approval accepted.');}catch(RuntimeException $error){self::assertStringContainsString('approval',$error->getMessage());}
    }
    public static function invalidExtensionDescriptors(): array
    {
        return [['max_core_version','0.9.0'],['min_core_version','2.0.0'],['extension_api_version','2.0.0'],['dependencies',['missing'=>'1.0.0']],['permissions',['*']],['id','../core'],['id','con'],['permissions',null],['version','bad']];
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidExtensionDescriptors')]
    public function testInvalidCompatibilityOrPermissionsCannotReachBackup(string $key,mixed $value): void
    {
        $this->descriptor[$key]=$value;$this->extensionPackage();$blocked=false;try{$this->extensionInstaller()->preview($this->extensionRoot.'/package');}catch(\Throwable){$blocked=true;}
        self::assertTrue($blocked);self::assertDirectoryDoesNotExist($this->extensionRoot.'/backup');self::assertNull($this->db->selectOne('SELECT id FROM installed_modules'));
    }
    public function testRawSqlCannotModifyCoreTablesThroughMigration(): void
    {
        $this->extensionPackage(['operations'=>[['op'=>'sql','sql'=>'DROP TABLE installed_modules']]]);$this->expectException(RuntimeException::class);$this->extensionInstaller()->preview($this->extensionRoot.'/package');
    }
    public function testFailedHealthLeavesDisabledAndExplicitRecoveryRestoresRealDatabaseAndFiles(): void
    {
        $installer=$this->extensionInstaller(health:static fn():bool=>false);try{$this->extensionInstall($installer);self::fail('Failed health enabled module.');}catch(RuntimeException $error){self::assertStringContainsString('health',$error->getMessage());}
        self::assertSame(0,(int)$this->db->selectOne('SELECT is_enabled FROM installed_modules')['is_enabled']);
        self::assertSame('failed',RestoreJournal::read(RestoreJournal::path($this->extensionRoot.'/modules'))['state']);
        self::assertTrue($installer->recover()['success']);self::assertNull($this->db->selectOne('SELECT id FROM installed_modules'));self::assertDirectoryDoesNotExist($this->extensionRoot.'/modules/demo');
        self::assertNotContains(ExtensionMigrationPlan::prefix('demo').'records',(new \Coleza\Domain\Backup\LogicalDatabaseDump())->tables($this->db));
    }
    public function testLegacyEnableCannotBypassPackagedExtensionHealth(): void
    {
        $this->extensionInstall(enable:false);$this->expectException(RuntimeException::class);(new ModuleLifecycleService($this->db))->enable('demo');
    }
    public function testHealthCannotWriteExtensionDataOrCoreSettings(): void
    {
        $denied=false;$installer=$this->extensionInstaller(health:function(string $id,$context)use(&$denied):bool{try{$context->setSetting('color','red');}catch(RuntimeException){$denied=true;}return $context->count('records')===0;});
        $this->extensionInstall($installer);self::assertTrue($denied);self::assertNull($installer->context('demo')->getSetting('color'));
    }
    public function testInstalledFileTamperingRevokesCapturedContext(): void
    {
        $installer=$this->extensionInstaller();$this->extensionInstall($installer);$context=$installer->context('demo');file_put_contents($this->extensionRoot.'/modules/demo/module.php','changed');
        $this->expectException(RuntimeException::class);$context->count('records');
    }
    public function testScopedRuntimeCannotQueryCoreTablesOrOtherStorage(): void
    {
        $installer=$this->extensionInstaller();$this->extensionInstall($installer);$context=$installer->context('demo');
        foreach([fn()=>$context->rows('installed_modules'),fn()=>$context->readFile('../other/private.txt'),fn()=>$context->setSetting('password','unsafe')] as $call){$blocked=false;try{$call();}catch(RuntimeException){$blocked=true;}self::assertTrue($blocked);}
        $context->setSetting('system.core_version','2.0.0');self::assertNull($this->db->selectOne('SELECT setting_value FROM system_settings WHERE setting_key=?',['system.core_version']));self::assertSame('2.0.0',$context->getSetting('system.core_version'));
    }
    public function testNetworkPermissionIsCheckedBeforeHostTransportInvocation(): void
    {
        $calls=[];$network=function(string $origin,string $path)use(&$calls):string{$calls[]=[$origin,$path];return 'ok';};
        $installer=$this->extensionInstaller(network:$network);$this->extensionInstall($installer);$context=$installer->context('demo');
        try{$context->request('https://api.example.test/anything');self::fail('Undeclared network access accepted.');}catch(RuntimeException){self::assertSame([],$calls);}
    }
    public function testDeclaredHttpsOriginCannotBeSwappedOrRedirectedByModuleArguments(): void
    {
        $this->descriptor['permissions'][]='network:https://api.example.test';$this->descriptor['permissions'][]='network:https://api.example.test:2087';$this->extensionPackage();$calls=[];
        $installer=$this->extensionInstaller(network:function(string $origin,string $path)use(&$calls):string{$calls[]=[$origin,$path];return 'ok';});$this->extensionInstall($installer);$context=$installer->context('demo');
        self::assertSame('ok',$context->request('https://api.example.test/status?x=1'));self::assertSame([['https://api.example.test','/status?x=1']],$calls);
        self::assertSame('ok',$context->request('https://api.example.test:2087/status'));self::assertSame(['https://api.example.test:2087','/status'],$calls[1]);
        foreach(['https://other.example.test/','http://api.example.test/','https://api.example.test@evil.test/','https://api.example.test:8443/','https://api.example.test//evil.test/'] as $url){$blocked=false;try{$context->request($url);}catch(RuntimeException){$blocked=true;}self::assertTrue($blocked);}self::assertCount(2,$calls);
    }
    public function testRawCallerTransactionIsRetainedBeforeInstallDdl(): void
    {
        $installer=$this->extensionInstaller();$preview=$installer->preview($this->extensionRoot.'/package');$pdo=$this->db->getPdo();$pdo->beginTransaction();
        try{try{$installer->install($this->extensionRoot.'/package',$preview['digest'],$preview['permissions']);self::fail('Caller transaction accepted.');}catch(RuntimeException){self::assertTrue($pdo->inTransaction());}}finally{$pdo->rollBack();}
    }
    public function testPublicRootCannotContainExtensionCode(): void
    {
        $this->expectException(RuntimeException::class);new ExtensionInstaller($this->db,new PackageSignatureVerifier($this->extensionKeys['public']),$this->extensionRoot.'/modules',$this->extensionRoot,new PreUpdateBackupService($this->extensionRoot.'/backup',$this->db));
    }
    public function testCapturedRouteStaysNamespacedAndRechecksDisable(): void
    {
        $installer=$this->extensionInstaller();$this->extensionInstall($installer);$router=new \Coleza\Foundation\Http\Router();$installer->context('demo')->route($router,'GET','/login',static fn()=>new \Coleza\Foundation\Http\Response('extension'));
        $routes=$router->getRoutes();self::assertSame('/extensions/demo/login',$routes[0]->getPattern());
        (new ModuleLifecycleService($this->db))->disable('demo');$this->expectException(RuntimeException::class);($routes[0]->getHandler())();
    }
    public function testMaximumCoreVersionCannotBeDiscardedByLegacyInstall(): void
    {
        $manifest=\Coleza\Domain\Module\ModuleManifest::fromArray(['id'=>'limited','name'=>'Limited','version'=>'1.0.0','type'=>'integration','min_core_version'=>'0.5.0','max_core_version'=>'0.9.0']);
        $denied=false;try{(new ModuleLifecycleService($this->db))->install($manifest);}catch(\Coleza\Foundation\Exceptions\ValidationException){$denied=true;}
        self::assertTrue($denied);self::assertNull($this->db->selectOne('SELECT id FROM installed_modules WHERE module_id=?',['limited']));
    }
    public function testLegacyModuleAuditFailureRollsBackInventoryInsert(): void
    {
        $sql=$this->db->getDriverName()==='mysql'?"CREATE TRIGGER extension_audit_failure BEFORE INSERT ON security_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='audit failure'":"CREATE TRIGGER extension_audit_failure BEFORE INSERT ON security_audit_logs BEGIN SELECT RAISE(ABORT,'audit failure'); END";$this->db->statement($sql);
        $manifest=\Coleza\Domain\Module\ModuleManifest::fromArray(['id'=>'audited','name'=>'Audited','version'=>'1.0.0','type'=>'integration']);$failed=false;
        try{(new ModuleLifecycleService($this->db,auditLogger:new \Coleza\Domain\Identity\Audit\AuditLogger($this->db)))->install($manifest);}catch(\PDOException){$failed=true;}
        self::assertTrue($failed);self::assertNull($this->db->selectOne('SELECT id FROM installed_modules WHERE module_id=?',['audited']));
    }
    public function testDirectLegacyEnableRejectsInjectedPackageReceipt(): void
    {
        $service=new ModuleLifecycleService($this->db);$manifest=\Coleza\Domain\Module\ModuleManifest::fromArray(['id'=>'unhealthy','name'=>'Unhealthy','version'=>'1.0.0','type'=>'integration']);$service->install($manifest);
        $data=$manifest->toArray();$data['_extension']=['state'=>'failed'];$this->db->update('installed_modules',['manifest'=>json_encode($data)],'module_id=:id',['id'=>'unhealthy']);
        $blocked=false;try{$service->enable('unhealthy');}catch(RuntimeException){$blocked=true;}self::assertTrue($blocked);self::assertSame(0,(int)$this->db->selectOne('SELECT is_enabled FROM installed_modules WHERE module_id=?',['unhealthy'])['is_enabled']);
    }
    public function testZipTraversalAndSpecialFileAreDeniedInQuarantine(): void
    {
        foreach(['traversal','symlink','bomb','case'] as $attack){
            $path=$this->extensionRoot.'/'.$attack.'.zip';$zip=new \ZipArchive();$zip->open($path,\ZipArchive::CREATE);
            if($attack==='traversal'){$zip->addFromString('../escape.txt','bad');}
            elseif($attack==='symlink'){$zip->addFromString('files/link','target');$zip->setExternalAttributesName('files/link',\ZipArchive::OPSYS_UNIX,0120777<<16);}
            elseif($attack==='bomb'){$zip->addFromString('files/large.txt',str_repeat('x',2097152));}
            else{$zip->addFromString('files/A.txt','one');$zip->addFromString('files/a.txt','two');}$zip->close();
            $blocked=false;try{$this->extensionInstaller()->preview($path);}catch(\Throwable){$blocked=true;}self::assertTrue($blocked,$attack);
        }self::assertFileDoesNotExist($this->extensionRoot.'/escape.txt');self::assertDirectoryDoesNotExist($this->extensionRoot.'/backup');
    }
    public function testActualSignedArchiveInstallsWithoutRequiringUploadedPhp(): void
    {
        $path=$this->extensionRoot.'/extension.zip';$zip=new \ZipArchive();$zip->open($path,\ZipArchive::CREATE);
        foreach(['manifest.json','manifest.sig','files/extension.json','files/module.php','files/migrations/001.json'] as $name){$zip->addFile($this->extensionRoot.'/package/'.$name,$name);}$zip->close();
        $installer=$this->extensionInstaller();$preview=$installer->preview($path);self::assertTrue($installer->install($path,$preview['digest'],$preview['permissions'])['success']);
    }
    public function testMigrationLedgerTamperingBlocksEnableAndRuntime(): void
    {
        $installer=$this->extensionInstaller();$this->extensionInstall($installer,enable:false);$this->db->statement('DELETE FROM `'.ExtensionMigrationPlan::prefix('demo').'migration_ledger`');
        $this->expectException(RuntimeException::class);$installer->enable('demo');
    }
    public function testHealthyHostAdapterCanEnableLaterWithoutReplayingMigrations(): void
    {
        $installer=$this->extensionInstaller();$this->extensionInstall($installer,enable:false);$installer->enable('demo');self::assertSame(0,$installer->context('demo')->count('records'));
        self::assertSame(1,(int)$this->db->getPdo()->query('SELECT COUNT(*) FROM `'.ExtensionMigrationPlan::prefix('demo').'migration_ledger`')->fetchColumn());
    }
    public function testActiveRecoveryGuardRevokesExistingRuntimeContext(): void
    {
        $installer=$this->extensionInstaller();$this->extensionInstall($installer);$context=$installer->context('demo');$this->db->update('system_settings',['setting_value'=>'{"state":"importing"}'],'setting_key=:key',['key'=>'system.update_migration_guard']);
        $this->expectException(RuntimeException::class);$context->count('records');
    }
    public function testScopedVaultReturnsOnlyOwnNamespaceAndRejectsWrongDatabase(): void
    {
        $this->descriptor['permissions'][]='vault:read';$this->extensionPackage();$vault=new \Coleza\Domain\Vault\VaultService($this->db,SecretFixtureKey::encryptor());$installer=$this->extensionInstaller(vault:$vault);$this->extensionInstall($installer);
        $vault->setSecret('module:demo','token','module-only');$vault->setSecret('module:other','token','other-only');$vault->setSecret('core','token','core-only');
        $context=\Coleza\Domain\Module\ExtensionContext::installed($this->db,str_replace('\\','/',realpath($this->extensionRoot.'/modules')),'demo','1.0.0',$vault);
        self::assertSame('module-only',$context->secret('token'));self::assertNull($context->secret('module:other/token'));
        $wrong=new \Coleza\Foundation\Database\Connection(new \PDO('sqlite::memory:'));$wrongVault=new \Coleza\Domain\Vault\VaultService($wrong,SecretFixtureKey::encryptor());
        $this->expectException(\LogicException::class);\Coleza\Domain\Module\ExtensionContext::installed($this->db,str_replace('\\','/',realpath($this->extensionRoot.'/modules')),'demo','1.0.0',$wrongVault);
    }
    public function testInstalledCoreVersionAndDependencyRevocationAreCurrent(): void
    {
        $dependency=\Coleza\Domain\Module\ModuleManifest::fromArray(['id'=>'dependency','name'=>'Dependency','version'=>'1.0.0','type'=>'integration']);$legacy=new ModuleLifecycleService($this->db);$legacy->install($dependency);$legacy->enable('dependency');
        $this->descriptor['dependencies']=['dependency'=>'1.0.0'];$this->extensionPackage();$installer=$this->extensionInstaller();$this->extensionInstall($installer);$context=$installer->context('demo');$legacy->disable('dependency');
        $denied=false;try{$context->count('records');}catch(RuntimeException){$denied=true;}self::assertTrue($denied);
        $this->db->insert('system_settings',['setting_key'=>'system.core_version','setting_value'=>'2.0.0']);
        $legacy->enable('dependency');self::assertTrue($legacy->isEnabled('dependency'));
        // Unbounded trusted dependency remains compatible, but packaged demo must not run on core 2.
        $this->expectException(RuntimeException::class);$context->count('records');
    }
    public function testFrozenApprovedPayloadSurvivesSourceMutationAfterBackup(): void
    {
        $fired=false;$installer=$this->extensionInstaller(checkpoint:function(string $stage)use(&$fired):void{if($stage==='extension_backup_verified'){$fired=true;file_put_contents($this->extensionRoot.'/package/files/module.php','untrusted mutation');}});
        $this->extensionInstall($installer);self::assertTrue($fired);self::assertStringContainsString('must not be implicitly booted',file_get_contents($this->extensionRoot.'/modules/demo/module.php'));
    }
    private function extensionWorkerConfig(): string
    {
        if($this->db->getDriverName()==='sqlite'){$this->db=new \Coleza\Foundation\Database\Connection(new \PDO('sqlite:'.$this->extensionRoot.'/database.sqlite'));$this->installExtensionSchema();$dsn='sqlite:'.$this->extensionRoot.'/database.sqlite';$database=null;}
        else{$dsn=getenv('COLEZA_TEST_MARIADB_DSN');$database=$this->db->getPdo()->query('SELECT DATABASE()')->fetchColumn();}
        $path=$this->extensionRoot.'/worker.json';file_put_contents($path,json_encode(['root'=>$this->extensionRoot,'public'=>$this->extensionKeys['public'],'dsn'=>$dsn,'database'=>$database],JSON_THROW_ON_ERROR));return $path;
    }
    private function extensionWorker(string $config,string $mode,bool $async=false): array
    {
        $process=proc_open([PHP_BINARY,'-c',php_ini_loaded_file(),__DIR__.'/extension-worker.php',$config,$mode],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);self::assertIsResource($process);fclose($pipes[0]);
        if($async){return [$process,$pipes];}
        try{$output=stream_get_contents($pipes[1]);self::assertSame('',stream_get_contents($pipes[2]));}finally{fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);}return [$exit,$output];
    }
    public static function extensionCrashStages(): array {return [['extension_ddl_applied'],['extension_published'],['extension_enabled']];}
    #[\PHPUnit\Framework\Attributes\DataProvider('extensionCrashStages')]
    public function testNewProcessRestoresInterruptedExtensionWithoutPhpReplay(string $stage): void
    {
        $config=$this->extensionWorkerConfig();[$exit,$output]=$this->extensionWorker($config,'kill:'.$stage);self::assertSame(76,$exit,$output);
        [$exit,$output]=$this->extensionWorker($config,'recover');self::assertSame(0,$exit,$output);self::assertSame('recovered',$output);self::assertNull($this->db->selectOne('SELECT id FROM installed_modules'));self::assertDirectoryDoesNotExist($this->extensionRoot.'/modules/demo');
        self::assertNotContains(ExtensionMigrationPlan::prefix('demo').'records',(new \Coleza\Domain\Backup\LogicalDatabaseDump())->tables($this->db));
    }
    public function testTwoProcessesCannotInstallThroughTheSameModuleRootLease(): void
    {
        $config=$this->extensionWorkerConfig();[$process,$pipes]=$this->extensionWorker($config,'hold',true);
        try{
            $deadline=microtime(true)+15;while(!file_exists($this->extensionRoot.'/ready')&&microtime(true)<$deadline){usleep(10000);}self::assertFileExists($this->extensionRoot.'/ready');
            $blocked=false;try{$this->extensionInstall();}catch(RuntimeException $error){$blocked=str_contains($error->getMessage(),'lock');}self::assertTrue($blocked);file_put_contents($this->extensionRoot.'/release','go');self::assertSame('installed',stream_get_contents($pipes[1]));self::assertSame('',stream_get_contents($pipes[2]));
        }finally{file_put_contents($this->extensionRoot.'/release','go');fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);}self::assertSame(0,$exit);self::assertCount(1,$this->db->select('SELECT * FROM installed_modules'));
    }
    public function testCorruptRecoveryBackupCannotDeleteFailedInstallation(): void
    {
        $installer=$this->extensionInstaller(health:static fn():bool=>false);try{$this->extensionInstall($installer);}catch(RuntimeException){}
        $state=RestoreJournal::read(RestoreJournal::path($this->extensionRoot.'/modules'));file_put_contents($this->extensionRoot.'/backup/'.$state['backup_id'].'.zip','corrupt');
        $blocked=false;try{$installer->recover();}catch(RuntimeException){$blocked=true;}self::assertTrue($blocked);self::assertDirectoryExists($this->extensionRoot.'/modules/demo');self::assertSame(0,(int)$this->db->selectOne('SELECT is_enabled FROM installed_modules')['is_enabled']);
    }
    public function testAuditFailureAfterDdlCannotEnableAndRealRecoveryDropsPartialSchema(): void
    {
        $fired=false;$installer=$this->extensionInstaller(checkpoint:function(string $stage)use(&$fired):void{if($stage==='extension_ddl_applied'&&!$fired){$fired=true;$sql=$this->db->getDriverName()==='mysql'?"CREATE TRIGGER extension_late_audit_failure BEFORE INSERT ON security_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='late audit failure'":"CREATE TRIGGER extension_late_audit_failure BEFORE INSERT ON security_audit_logs BEGIN SELECT RAISE(ABORT,'late audit failure'); END";$this->db->statement($sql);}});
        $failed=false;try{$this->extensionInstall($installer);}catch(\PDOException){$failed=true;}self::assertTrue($failed);self::assertTrue($fired);self::assertNull($this->db->selectOne('SELECT id FROM installed_modules'));
        self::assertTrue($installer->recover()['success']);self::assertNotContains(ExtensionMigrationPlan::prefix('demo').'records',(new \Coleza\Domain\Backup\LogicalDatabaseDump())->tables($this->db));
    }
    public function testMissingHostHealthCannotEnableEvenWhenPermissionsAreApproved(): void
    {
        $installer=new ExtensionInstaller($this->db,new PackageSignatureVerifier($this->extensionKeys['public']),$this->extensionRoot.'/modules',$this->extensionRoot.'/public',new PreUpdateBackupService($this->extensionRoot.'/backup',$this->db));
        $blocked=false;try{$this->extensionInstall($installer);}catch(RuntimeException $error){$blocked=str_contains($error->getMessage(),'health');}self::assertTrue($blocked);self::assertDirectoryDoesNotExist($this->extensionRoot.'/backup');
    }
    public function testModuleDiscoveryCannotReinterpretPackagedExtension(): void
    {
        $this->extensionInstall();self::assertSame([],(new ModuleLifecycleService($this->db))->discover($this->extensionRoot.'/modules'));
    }
}
