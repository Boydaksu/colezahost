<?php
declare(strict_types=1);
namespace Coleza\Domain\Module;
use Coleza\Foundation\Database\{Connection,PdoSchema};
use Coleza\Domain\Backup\{BackupFileTree,PreUpdateBackupService,RecoveryLease,RestoreJournal,RestoreWizardService};
use Coleza\Domain\Backup\Storage\{LocalBackupStorageAdapter,BackupPackage};
use Coleza\Domain\Updater\{PackageSignatureVerifier,UpdatePackageSnapshot,UpdatePackageManifest,UpdateFileTree,UpdateHealthGate};
use Coleza\Domain\OperationalMode\{OperationalMode,OperationalModeManager};
use Coleza\Domain\Vault\VaultService;
use RuntimeException;

/** Explicit offline installer. Uploaded PHP is data, not an autoload/health hook.
 * Host adapters use ExtensionContext; unsigned/raw SQL migrations are never executed.
 */
final class ExtensionInstaller
{
    private static ?\WeakMap $memory=null;
    public function __construct(private Connection $db,private PackageSignatureVerifier $verifier,private string $moduleRoot,private string $publicRoot,
        private PreUpdateBackupService $backup,private string $coreVersion='1.0.0',private ?\Closure $adapterHealth=null,
        private ?VaultService $vault=null,private ?\Closure $network=null,private ?\Closure $checkpoint=null,private ?UpdateHealthGate $health=null)
    {
        foreach([$moduleRoot,$publicRoot] as $root){UpdatePackageSnapshot::assertNoLinks($root);if(!is_dir($root)){throw new RuntimeException('Explicit existing extension/public roots are required.');}}
        $this->moduleRoot=str_replace('\\','/',realpath($moduleRoot));$this->publicRoot=str_replace('\\','/',realpath($publicRoot));
        if(UpdatePackageSnapshot::overlaps($this->moduleRoot,$this->publicRoot)||UpdatePackageSnapshot::overlaps(dirname($this->moduleRoot).'/extension-data',$this->publicRoot)){throw new RuntimeException('Extension code/data must be outside the public root.');}
        $temp=str_replace('\\','/',realpath(sys_get_temp_dir()));$public=$this->publicRoot;if(PHP_OS_FAMILY==='Windows'){$temp=strtolower($temp);$public=strtolower($public);}if($temp===$public||str_starts_with($temp,$public.'/')){throw new RuntimeException('Extension quarantine must be outside the public root.');}
    }
    private function identity(): string
    {
        if($this->db->getDriverName()==='mysql'){return hash('sha256',json_encode([$this->db->selectOne('SELECT @@hostname AS host,@@port AS port'),$this->db->getPdo()->query('SELECT DATABASE()')->fetchColumn()],JSON_THROW_ON_ERROR));}
        foreach($this->db->select('PRAGMA database_list') as $row){if($row['name']==='main'&&$row['file']!==''){if(UpdatePackageSnapshot::overlaps($row['file'],$this->moduleRoot)){throw new RuntimeException('Extension database must be outside the module tree.');}return hash('sha256',realpath($row['file']));}}
        self::$memory??=new \WeakMap();$pdo=$this->db->getPdo();return self::$memory[$pdo]??=bin2hex(random_bytes(16));
    }
    private function compatible(ModuleManifest $manifest): void
    {
        ModuleLifecycleService::assertInstalledSchema($this->db);
        if(!PdoSchema::useInstalledSchema($this->db,['system_settings'=>['setting_key','setting_value']])){throw new RuntimeException('Installed settings schema is required.');}
        $installed=$this->db->selectOne('SELECT setting_value FROM system_settings WHERE setting_key=?',['system.core_version']);
        if($installed!==null&&$installed['setting_value']!==$this->coreVersion){throw new RuntimeException('Extension installer core version is stale.');}
        if($manifest->extensionApiVersion!=='1.0.0'||UpdatePackageManifest::compareVersions($this->coreVersion,$manifest->minCoreVersion)<0||($manifest->maxCoreVersion!==null&&UpdatePackageManifest::compareVersions($this->coreVersion,$manifest->maxCoreVersion)>0)){throw new RuntimeException('Extension Core/API compatibility failed.');}
        foreach($manifest->dependencies as $id=>$version){$row=$this->db->selectOne('SELECT version,is_enabled FROM installed_modules WHERE module_id=?',[$id]);if($row===null||(int)$row['is_enabled']!==1||UpdatePackageManifest::compareVersions($row['version'],$version)<0){throw new RuntimeException('Extension dependency is missing, disabled or outdated.');}}
    }
    private function inspect(ExtensionPackage $package): array
    {
        $manifest=$package->manifest;$this->compatible($manifest);
        if($this->db->selectOne('SELECT id FROM installed_modules WHERE module_id=?',[$manifest->id])!==null||file_exists($this->moduleRoot.'/'.$manifest->id)||is_link($this->moduleRoot.'/'.$manifest->id)){throw new RuntimeException('Extension already installed or target occupied.');}
        $plan=ExtensionMigrationPlan::compile($package,$this->db);$prefix=ExtensionMigrationPlan::prefix($manifest->id);
        $tables=$this->db->getDriverName()==='mysql'?array_column($this->db->select('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()'),'TABLE_NAME'):array_column($this->db->select("SELECT name FROM sqlite_master WHERE type='table'"),'name');
        foreach($tables as $table){if(str_starts_with($table,$prefix)){throw new RuntimeException('Orphan extension tables require explicit recovery; no automatic replay.');}}
        return ['module'=>$manifest->toArray(),'digest'=>$package->digest,'permissions'=>$manifest->permissions,'migrations'=>$plan['migrations'],'tables'=>$plan['tables'],'files'=>array_keys($package->snapshot->manifest->getFileChecksums())];
    }
    public function preview(string $source): array
    {
        $path=realpath($source);if($path!==false&&UpdatePackageSnapshot::overlaps($path,$this->publicRoot)){throw new RuntimeException('Uploaded extension source must be outside the public root.');}
        $package=ExtensionPackage::open($source,$this->verifier);try{return $this->inspect($package);}finally{$package->close();}
    }
    private function journal(): string {return RestoreJournal::path($this->moduleRoot);}
    private function guard(string $state,array $context): void
    {
        $suffix=$this->db->getDriverName()==='mysql'?' ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)':' ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value';
        $this->db->statement('INSERT INTO system_settings(setting_key,setting_value) VALUES (?,?)'.$suffix,['system.update_migration_guard',json_encode(['state'=>$state,'purpose'=>'extension','backup_id'=>$context['backup_id']],JSON_THROW_ON_ERROR)]);
    }
    private function assertNoRecovery(): void
    {
        $prior=RestoreJournal::read($this->journal());if($prior!==null&&$prior['state']!=='complete'){throw new RuntimeException('Extension recovery is required before another operation.');}
        $guard=$this->db->selectOne('SELECT setting_value FROM system_settings WHERE setting_key=?',['system.update_migration_guard']);
        if($guard!==null&&(ExtensionPackage::decode($guard['setting_value'])['state']??null)!=='complete'){throw new RuntimeException('Database recovery guard blocks extension installation.');}
    }
    public function install(string $source,string $approvedDigest,array $approvedPermissions,bool $enable=true,?int $actorUserId=null): array
    {
        $package=ExtensionPackage::open($source,$this->verifier);$lease=null;$state=null;
        try{
            $lease=new RecoveryLease($this->db,$this->moduleRoot);$this->assertNoRecovery();$preview=$this->inspect($package);
            $expected=$preview['permissions'];sort($expected);$approved=$approvedPermissions;sort($approved);
            if(!hash_equals($package->digest,$approvedDigest)||$expected!==$approved){throw new RuntimeException('Extension package/permission approval does not match frozen preview.');}
            if($enable&&$this->adapterHealth===null){throw new RuntimeException('A host-owned extension adapter health contract is required before enable.');}
            $this->backup->assertContext($this->db,$this->coreVersion);$store=$this->backup->getBackupStorageDir();UpdatePackageSnapshot::assertNoLinks($store);
            if(!is_dir($store)&&!mkdir($store,0700,true)){throw new RuntimeException('Cannot create private extension backup storage.');}$store=realpath($store);
            $sourcePath=realpath($source);if($sourcePath===false||UpdatePackageSnapshot::overlaps($store,$this->moduleRoot)||UpdatePackageSnapshot::overlaps($sourcePath,$this->moduleRoot)||UpdatePackageSnapshot::overlaps($store,$this->publicRoot)||UpdatePackageSnapshot::overlaps($sourcePath,$this->publicRoot)||UpdatePackageSnapshot::overlaps($sourcePath,$store)){throw new RuntimeException('Extension source/backup/module/public trees must be separate.');}
            ($this->health??new UpdateHealthGate($this->db))->verify($this->db);
            $database=$this->identity();$old=UpdateFileTree::inventory($this->moduleRoot);$backup=$this->backup->createVerifiedPreUpdateBackup($this->coreVersion,UpdateFileTree::paths($this->moduleRoot));$id=$backup['manifest']->getBackupId();
            $this->checkpoint?->__invoke('extension_backup_verified',[]);
            if(!$this->backup->verifyBackup($id)->isValid()||UpdateFileTree::inventory($this->moduleRoot)!==$old){throw new RuntimeException('Extension backup/tree changed before effects.');}
            $this->inspect($package);$manifest=$package->manifest;$prepared=dirname($this->moduleRoot).'/.extension_'.$manifest->id.'_'.bin2hex(random_bytes(12));
            $state=['state'=>'preparing','purpose'=>'extension','target'=>$this->moduleRoot,'database'=>$database,'backup_id'=>$id,'source_version'=>$this->coreVersion,
                'manifest_sha256'=>$package->digest,'backup_sha256'=>hash_file('sha256',$store.'/'.$id.'.zip'),'backup_manifest_sha256'=>hash_file('sha256',$store.'/'.$id.'.manifest.json'),
                'module_id'=>$manifest->id,'module_version'=>$manifest->version,'prepared_directory'=>$prepared,'migrations_run'=>0];
            (new OperationalModeManager($this->db))->transitionTo(OperationalMode::RECOVERY,'Extension installation awaits verified activation');$this->guard('importing',$state);RestoreJournal::write($this->journal(),$state);
            if(!mkdir($prepared,0700)){throw new RuntimeException('Cannot prepare extension directory.');}
            foreach($package->snapshot->manifest->getFileChecksums() as $name=>$hash){$path=$prepared.'/'.$name;if(!is_dir(dirname($path))&&!mkdir(dirname($path),0700,true)){throw new RuntimeException('Cannot prepare extension parent.');}if(!copy($package->snapshot->directory().'/files/'.$name,$path)||!hash_equals($hash,(string)hash_file('sha256',$path))){throw new RuntimeException('Cannot prepare verified extension file.');}chmod($path,0600);$handle=fopen($path,'r+b');if($handle===false){throw new RuntimeException('Cannot reopen extension snapshot.');}try{if(!fsync($handle)){throw new RuntimeException('Cannot persist extension snapshot.');}}finally{fclose($handle);}}
            $tree=UpdateFileTree::digest(UpdateFileTree::inventory($prepared));$state['state']='importing';RestoreJournal::write($this->journal(),$state);
            $ledger=ExtensionMigrationPlan::prefix($manifest->id).'migration_ledger';
            $this->db->statement('CREATE TABLE `'.$ledger.'` (migration VARCHAR(255) PRIMARY KEY,sha256 VARCHAR(64) NOT NULL)'.($this->db->getDriverName()==='mysql'?' ENGINE=InnoDB':''));
            foreach($preview['migrations'] as $migration){
                $apply=function()use($migration,$lease,$state,$ledger):void{
                    foreach($migration['sql'] as $sql){$lease->assertOwns($this->db,$this->moduleRoot);$this->db->statement($sql);$this->checkpoint?->__invoke('extension_ddl_applied',$state);}
                    $this->db->insert($ledger,['migration'=>$migration['file'],'sha256'=>$migration['sha256']]);
                };
                if($this->db->getDriverName()==='sqlite'){$this->db->transaction($apply);}else{$apply();}
                $state['migrations_run']++;RestoreJournal::write($this->journal(),$state);
            }
            if(!hash_equals($tree,UpdateFileTree::digest(UpdateFileTree::inventory($prepared)))){throw new RuntimeException('Prepared extension changed during migration.');}
            $state['state']='publishing';RestoreJournal::write($this->journal(),$state);
            if(file_exists($this->moduleRoot.'/'.$manifest->id)||!rename($prepared,$this->moduleRoot.'/'.$manifest->id)){throw new RuntimeException('Cannot publish extension directory.');}
            $proof=['state'=>'installed','permissions'=>$preview['permissions'],'digest'=>$package->digest,'tree_sha256'=>$tree,'tables'=>$preview['tables'],'migration_ledger'=>$ledger];$data=$manifest->toArray();$data['_extension']=$proof;
            $audit=new \Coleza\Domain\Identity\Audit\AuditLogger($this->db);$audit->ensureTable();
            $this->db->transaction(function()use($manifest,$data,$audit,$actorUserId):void{
                $this->db->insert('installed_modules',['module_id'=>$manifest->id,'name'=>$manifest->name,'version'=>$manifest->version,'type'=>$manifest->type,'is_enabled'=>0,'manifest'=>json_encode($data,JSON_THROW_ON_ERROR)]);
                $audit->log($actorUserId??0,'EXTENSION_INSTALLED','module:'.$manifest->id,payload:['version'=>$manifest->version]);
            });
            $state['state']='verifying';RestoreJournal::write($this->journal(),$state);$this->checkpoint?->__invoke('extension_published',$state);
            ($this->health??new UpdateHealthGate($this->db))->verify($this->db);$this->compatible($manifest);
            if($enable){$this->enableUnderLease($manifest->id,$lease,$actorUserId);$this->checkpoint?->__invoke('extension_enabled',$state);}
            $this->guard('complete',$state);$state['state']='complete';$state['outcome']='installed';RestoreJournal::write($this->journal(),$state);
            return ['success'=>true,'module_id'=>$manifest->id,'enabled'=>$enable,'migrations_run'=>$state['migrations_run'],'backup_id'=>$id,'recovery_required'=>true];
        }catch(\Throwable $error){if($state!==null){$state['state']='failed';RestoreJournal::write($this->journal(),$state);}throw $error;}
        finally{try{$lease?->close();}finally{$package->close();}}
    }
    private function enableUnderLease(string $id,RecoveryLease $lease,?int $actor): void
    {
        if($this->adapterHealth===null){throw new RuntimeException('Extension adapter health contract is unavailable.');}
        $context=ExtensionContext::health($this->db,$this->moduleRoot,$id,$this->coreVersion,$lease,$this->vault,$this->network);
        if(($this->adapterHealth)($id,$context)!==true){throw new RuntimeException('Extension adapter health failed; module remains disabled.');}
        ($this->health??new UpdateHealthGate($this->db))->verify($this->db);
        $context=ExtensionContext::health($this->db,$this->moduleRoot,$id,$this->coreVersion,$lease,$this->vault,$this->network);
        $audit=new \Coleza\Domain\Identity\Audit\AuditLogger($this->db);$audit->ensureTable();
        $this->db->transaction(function()use($id,$actor,$audit):void{$this->db->update('installed_modules',['is_enabled'=>1],'module_id=:id',['id'=>$id]);$audit->log($actor??0,'EXTENSION_ENABLED','module:'.$id);});
    }
    public function enable(string $id,?int $actor=null): void
    {
        $lease=new RecoveryLease($this->db,$this->moduleRoot);try{$this->assertNoRecovery();$this->enableUnderLease($id,$lease,$actor);}finally{$lease->close();}
    }
    public function context(string $id): ExtensionContext {return ExtensionContext::installed($this->db,$this->moduleRoot,$id,$this->coreVersion,$this->vault,$this->network);}
    public function recover(?RestoreWizardService $restore=null): array
    {
        $lease=new RecoveryLease($this->db,$this->moduleRoot);try{
            $state=RestoreJournal::read($this->journal());if($state===null||($state['purpose']??null)!=='extension'||($state['target']??null)!==$this->moduleRoot||($state['database']??null)!==$this->identity()){throw new RuntimeException('Bound extension recovery journal is required.');}
            if($state['state']==='complete'&&($state['outcome']??null)==='rolled_back'){return ['success'=>true,'already_recovered'=>true];}
            $id=$state['backup_id'];BackupPackage::id($id);$this->backup->assertContext($this->db,$this->coreVersion);$store=$this->backup->getBackupStorageDir();
            if(!$this->backup->verifyBackup($id)->isValid()){throw new RuntimeException('Extension recovery backup verification failed.');}
            $restore??=new RestoreWizardService($store.'/extension-recovery');$result=$restore->executeExtensionRollback($id,new LocalBackupStorageAdapter($store),$this->db,$this->moduleRoot,$lease,$state);
            if(!$result->isSuccess()){throw new RuntimeException('Extension rollback failed: '.$result->getErrorMessage());}
            try{($this->health??new UpdateHealthGate($this->db))->verify($this->db);$this->guard('complete',$state);$state['state']='complete';$state['outcome']='rolled_back';RestoreJournal::write($this->journal(),$state);}
            catch(\Throwable $error){$state['state']='failed';RestoreJournal::write($this->journal(),$state);throw $error;}
            return ['success'=>true,'already_recovered'=>false,'recovery_required'=>true];
        }finally{$lease->close();}
    }
}
