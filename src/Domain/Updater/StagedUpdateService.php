<?php
declare(strict_types=1);
namespace Coleza\Domain\Updater;

use Coleza\Domain\Backup\{BackupFileTree,PreUpdateBackupService,RestoreWizardService,RestoreJournal,BackupManifest};
use Coleza\Domain\Backup\Storage\LocalBackupStorageAdapter;
use Coleza\Foundation\Database\Connection;
use Closure;
use RuntimeException;
use Throwable;

/** Offline signed staged update and explicit verified rollback. Normal activation is separate. */
final class StagedUpdateService
{
    public function __construct(
        private PackageSignatureVerifier $signatureVerifier,
        private ModuleCompatibilityChecker $moduleChecker,
        private string $currentCoreVersion='1.0.0',
        private ?Connection $db=null,
        private ?PreUpdateBackupService $backupService=null,
        private ?Closure $checkpoint=null,
        private ?UpdateHealthGate $health=null
    ) {}
    public function getCurrentCoreVersion(): string
    {
        if($this->db!==null&&\Coleza\Foundation\Database\PdoSchema::useInstalledSchema($this->db,['system_settings'=>['setting_key','setting_value']])){
            $row=$this->db->selectOne('SELECT setting_value FROM system_settings WHERE setting_key=?',['system.core_version']);
            if($row!==null){UpdatePackageManifest::assertVersion($row['setting_value']);return $row['setting_value'];}
        }
        return $this->currentCoreVersion;
    }

    /** Directory and distribution ZIP use the identical signed manifest/files contract. */
    private function inspect(string $source): array
    {
        $package=null;$signed=false;$checksums=false;$version='0.0.0';
        try {
            $package=is_file($source)?UpdatePackageSnapshot::fromArchive($source,$this->signatureVerifier):UpdatePackageSnapshot::fromDirectory($source,$this->signatureVerifier);
            $signed=true;$checksums=true;$manifest=$package->manifest;$version=$manifest->getVersion();
            if($this->getCurrentCoreVersion()!==$this->currentCoreVersion){throw new RuntimeException('Installed version differs from configured update source version.');}
            UpdatePackageManifest::assertVersion($this->currentCoreVersion);
            if(UpdatePackageManifest::compareVersions($this->currentCoreVersion,$manifest->getMinCurrentVersion())<0){throw new RuntimeException('Current core version is lower than update prerequisite.');}
            if(UpdatePackageManifest::compareVersions($version,$this->currentCoreVersion)<=0){throw new RuntimeException('Update must advance the installed core version.');}
            $modules=$this->moduleChecker->checkCompatibility($version,$manifest->getRequiredModules());
            return [new StagedUpdateReport($source,$version,$this->currentCoreVersion,true,true,$modules['compatible'],incompatibleModules:$modules['incompatible_modules'],migrations:$manifest->getMigrations()),$package];
        } catch(Throwable $error) {
            $package?->close();
            $failed=[];
            if($error instanceof UpdatePackageException){$signed=$error->signatureValid;$failed=array_merge($error->tampered,$error->missing);}
            return [new StagedUpdateReport($source,$version,$this->currentCoreVersion,$signed,$checksums,false,failedChecksumFiles:$failed,error:$error->getMessage()),null];
        }
    }
    public function validateStagedPackage(string $stagedDir): StagedUpdateReport
    {
        [$report,$package]=$this->inspect($stagedDir);$package?->close();return $report;
    }

    /** @param array<int|string,string> $backupExtraFiles Explicit extra sources, in a reserved separate namespace. */
    public function applyValidatedUpdate(string $stagedDir,string $targetAppDir,bool $requireBackup=true,array $backupExtraFiles=[]): array
    {
        if(!$requireBackup){throw new RuntimeException('Mandatory pre-update backup cannot be bypassed.');}
        [$report,$package]=$this->inspect($stagedDir);$session=null;
        try {
            if(!$report->isReadyToApply()||$package===null){throw new RuntimeException('Cannot apply update: package validation failed: '.($report->getError()??'module mismatch'));}
            if($this->db===null||$this->backupService===null){throw new RuntimeException('Mandatory pre-update backup failed: Database and backup service must be configured.');}
            $this->moduleChecker->assertConnection($this->db);$this->backupService->assertContext($this->db,$this->currentCoreVersion);
            UpdateHealthGate::assertPayloadSyntax($package);
            UpdatePackageSnapshot::assertNoLinks($targetAppDir);$target=realpath($targetAppDir);
            if($target===false||!is_dir($target)){throw new RuntimeException('Update target must be an existing application directory.');}
            $target=str_replace('\\','/',$target);
            $source=realpath($stagedDir);
            if($source===false||UpdatePackageSnapshot::overlaps($source,$target)||UpdatePackageSnapshot::overlaps($package->directory(),$target)){throw new RuntimeException('Update staging and target must be disjoint.');}
            $store=$this->backupService->getBackupStorageDir();
            if(!is_dir($store)&&!mkdir($store,0700,true)){throw new RuntimeException('Cannot create private update backup storage.');}
            UpdatePackageSnapshot::assertNoLinks($store);$store=realpath($store);
            if($store===false||UpdatePackageSnapshot::overlaps($target,$store)||UpdatePackageSnapshot::overlaps($source,$store)){throw new RuntimeException('Update backup storage must be outside target and staging trees.');}
            $session=new UpdateMigrationSession($this->db,$package,$target,$store,$this->currentCoreVersion);
            $this->preflightDestinations($target,$package->manifest->getFileChecksums());
            if(file_exists($target.'/_update_extra')){throw new RuntimeException('Target uses the reserved update backup extra namespace.');}
            $this->checkpoint?->__invoke('package_frozen');
            $paths=UpdateFileTree::paths($target);
            foreach($backupExtraFiles as $alias=>$path){$alias=is_string($alias)?$alias:basename(str_replace('\\','/',$path));BackupFileTree::path($alias);$key='_update_extra/'.$alias;if(isset($paths[$key])){throw new RuntimeException('Duplicate update backup alias.');}$paths[$key]=$path;}
            $backup=$this->backupService->createVerifiedPreUpdateBackup($package->manifest->getVersion(),$paths);$id=$backup['manifest']->getBackupId();
            if(!$backup['verification']->isValid()){throw new RuntimeException('Mandatory pre-update backup verification failed.');}
            $this->checkpoint?->__invoke('backup_verified');
            if(!$this->backupService->verifyBackup($id)->isValid()){throw new RuntimeException('Mandatory backup changed before update.');}
            $expected=[];foreach($backup['manifest']->getContainedFiles() as $name=>$hash){if(str_starts_with($name,'files/')&&!str_starts_with($name,'files/_update_extra/')){$expected[substr($name,6)]=$hash;}}
            $current=UpdateFileTree::inventory($target);ksort($expected);ksort($current);
            if($expected!==$current){throw new RuntimeException('Application tree changed after backup; stop writers and retry.');}
            $this->preflightDestinations($target,$package->manifest->getFileChecksums());
            if(!$this->moduleChecker->checkCompatibility($package->manifest->getVersion(),$package->manifest->getRequiredModules())['compatible']){throw new RuntimeException('Module compatibility changed after backup.');}
            ($this->health??new UpdateHealthGate($this->db))->verify($this->db);
            $suffix=bin2hex(random_bytes(12));$prepared=dirname($target).'/.'.basename($target).'.update_'.$suffix;$previous=dirname($target).'/.'.basename($target).'.previous_'.$suffix;
            $session->begin($id,$this->currentCoreVersion,$target,$prepared,$previous,
                (string)hash_file('sha256',$store.'/'.$id.'.zip'),(string)hash_file('sha256',$store.'/'.$id.'.manifest.json'));
            $digest=UpdateFileTree::prepare($target,$prepared,$package,$current);$session->step('preparing',['expected_tree_sha256'=>$digest]);
            $this->checkpoint?->__invoke('tree_prepared',$session->context());
            $this->assertTree($prepared,$digest);
            $migrations=$session->run($id);$this->checkpoint?->__invoke('migrations_applied',$session->context());
            if(!$this->moduleChecker->checkCompatibility($package->manifest->getVersion(),$package->manifest->getRequiredModules())['compatible']){throw new RuntimeException('Module compatibility changed during migrations.');}
            ($this->health??new UpdateHealthGate($this->db))->verify($this->db);
            if(UpdateFileTree::inventory($target)!==$current){throw new RuntimeException('Active application tree changed before update publication.');}
            $this->assertTree($prepared,$digest);$session->step('publishing');
            if(file_exists($previous)||!rename($target,$previous)){throw new RuntimeException('Cannot preserve previous application tree.');}
            $this->checkpoint?->__invoke('old_tree_preserved',$session->context());
            if(!rename($prepared,$target)){throw new RuntimeException('Cannot publish prepared application tree; explicit recovery required.');}
            $this->checkpoint?->__invoke('new_tree_published',$session->context());
            $this->assertTree($target,$digest);($this->health??new UpdateHealthGate($this->db))->verify($this->db);
            $session->complete();$this->checkpoint?->__invoke('version_persisted',$session->context());
            return ['success'=>true,'target_version'=>$package->manifest->getVersion(),'files_applied'=>count($package->manifest->getFileChecksums()),'migrations_run'=>$migrations,'backup_id'=>$id,'previous_directory'=>$previous,'recovery_required'=>true];
        } catch(Throwable $error){$session?->fail();throw $error;}
        finally {try{$session?->close();}finally{$package?->close();}}
    }
    private function assertTree(string $path,string $digest): void
    {
        if(!is_dir($path)||!hash_equals($digest,UpdateFileTree::digest(UpdateFileTree::inventory($path)))){throw new RuntimeException('Prepared/published application tree integrity failed.');}
    }
    /** Explicit offline recovery; never replays update PHP or trusts a half-imported ledger. */
    public function recoverFailedUpdate(string $targetAppDir,?RestoreWizardService $restore=null): array
    {
        if($this->db===null||$this->backupService===null){throw new RuntimeException('Recovery requires the bound database and backup service.');}
        $this->backupService->assertContext($this->db,$this->currentCoreVersion);
        UpdatePackageSnapshot::assertNoLinks($targetAppDir);$parent=realpath(dirname($targetAppDir));$base=basename($targetAppDir);
        if($parent===false||in_array($base,['','.','..'],true)){throw new RuntimeException('Recovery requires an existing target parent.');}
        $target=str_replace('\\','/',$parent).'/'.$base;
        // realpath uses platform separators; update journals use that same spelling.
        $target=str_replace('\\','/',is_dir($target)?realpath($target):$parent.DIRECTORY_SEPARATOR.$base);
        $store=realpath($this->backupService->getBackupStorageDir());
        if($store===false||UpdatePackageSnapshot::overlaps($target,$store)){throw new RuntimeException('Recovery backup storage must be outside the application tree.');}
        $session=new UpdateMigrationSession($this->db,null,$target,$store);
        try{
            $context=$session->context();$id=$context['backup_id'];
            \Coleza\Domain\Backup\Storage\BackupPackage::id($id);
            if(($context['outcome']??null)==='rolled_back'&&$context['state']==='complete'){return ['success'=>true,'already_recovered'=>true,'backup_id'=>$id];}
            if(!is_file($store.'/'.$id.'.zip')||!is_file($store.'/'.$id.'.manifest.json')||!isset($context['backup_sha256'],$context['backup_manifest_sha256'])
                ||!hash_equals($context['backup_sha256'],(string)hash_file('sha256',$store.'/'.$id.'.zip'))
                ||!hash_equals($context['backup_manifest_sha256'],(string)hash_file('sha256',$store.'/'.$id.'.manifest.json'))
                ||!$this->backupService->verifyBackup($id)->isValid()){throw new RuntimeException('Bound recovery backup is unavailable or changed.');}
            $this->checkpoint?->__invoke('recovery_backup_verified',$context);
            $manifest=BackupManifest::fromArray(json_decode(file_get_contents($store.'/'.$id.'.manifest.json'),true,flags:JSON_THROW_ON_ERROR));
            if($manifest->getType()!=='pre_update'||$manifest->getSourceAppVersion()!==$context['source_version']){throw new RuntimeException('Recovery backup source version does not match update journal.');}
            $restore??=new RestoreWizardService($store.'/update-recovery');
            $result=$restore->executeUpdateRollback($id,new LocalBackupStorageAdapter($store),$this->db,$target,$session->lease(),$context);
            if(!$result->isSuccess()){throw new RuntimeException('Update rollback failed: '.$result->getErrorMessage());}
            try{
                ($this->health??new UpdateHealthGate($this->db))->verify($this->db);
                if(!$this->moduleChecker->checkCompatibility($context['source_version'])['compatible']){throw new RuntimeException('Restored source module compatibility failed.');}
                $session->finishRollback();
            }
            catch(Throwable $error){$session->fail();throw new RuntimeException('Update rollback final verification failed; recovery remains required.',0,$error);}
            return ['success'=>true,'already_recovered'=>false,'backup_id'=>$id,'source_version'=>$context['source_version'],'recovery_required'=>true];
        }finally{$session->close();}
    }
    private function preflightDestinations(string $target,array $files): void
    {
        foreach($files as $relative=>$_){$path=$target.'/'.$relative;UpdatePackageSnapshot::assertNoLinks($path);
            if($relative==='_update_extra'||str_starts_with($relative,'_update_extra/')){throw new RuntimeException('Update payload uses the reserved backup extra namespace.');}
            if(is_dir($path)){throw new RuntimeException('Update destination collides with an existing directory.');}
            $parent=dirname($path);while($parent!==$target&&strlen($parent)>strlen($target)){if(file_exists($parent)&&!is_dir($parent)){throw new RuntimeException('Update destination parent is a file.');}$parent=dirname($parent);}
        }
    }
}
