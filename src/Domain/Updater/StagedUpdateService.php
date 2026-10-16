<?php
declare(strict_types=1);
namespace Coleza\Domain\Updater;

use Coleza\Domain\Backup\{BackupFileTree,PreUpdateBackupService};
use Coleza\Foundation\Database\Connection;
use Closure;
use RuntimeException;
use Throwable;

/** Signed admission, mandatory backup and journaled migrations; staged publication remains separate. */
final class StagedUpdateService
{
    public function __construct(
        private PackageSignatureVerifier $signatureVerifier,
        private ModuleCompatibilityChecker $moduleChecker,
        private string $currentCoreVersion='1.0.0',
        private ?Connection $db=null,
        private ?PreUpdateBackupService $backupService=null,
        private ?Closure $checkpoint=null
    ) {}
    public function getCurrentCoreVersion(): string {return $this->currentCoreVersion;}

    /** Directory and distribution ZIP use the identical signed manifest/files contract. */
    private function inspect(string $source): array
    {
        $package=null;$signed=false;$checksums=false;$version='0.0.0';
        try {
            $package=is_file($source)?UpdatePackageSnapshot::fromArchive($source,$this->signatureVerifier):UpdatePackageSnapshot::fromDirectory($source,$this->signatureVerifier);
            $signed=true;$checksums=true;$manifest=$package->manifest;$version=$manifest->getVersion();
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
            UpdatePackageSnapshot::assertNoLinks($targetAppDir);$target=realpath($targetAppDir);
            if($target===false||!is_dir($target)){throw new RuntimeException('Update target must be an existing application directory.');}
            $source=realpath($stagedDir);
            if($source===false||UpdatePackageSnapshot::overlaps($source,$target)||UpdatePackageSnapshot::overlaps($package->directory(),$target)){throw new RuntimeException('Update staging and target must be disjoint.');}
            $store=$this->backupService->getBackupStorageDir();
            if(!is_dir($store)&&!mkdir($store,0700,true)){throw new RuntimeException('Cannot create private update backup storage.');}
            UpdatePackageSnapshot::assertNoLinks($store);$store=realpath($store);
            if($store===false||UpdatePackageSnapshot::overlaps($target,$store)||UpdatePackageSnapshot::overlaps($source,$store)){throw new RuntimeException('Update backup storage must be outside target and staging trees.');}
            $session=new UpdateMigrationSession($this->db,$package,$target,$store);
            $this->preflightDestinations($target,$package->manifest->getFileChecksums());
            if(file_exists($target.'/_update_extra')){throw new RuntimeException('Target uses the reserved update backup extra namespace.');}
            $this->checkpoint?->__invoke('package_frozen');
            $paths=$this->targetPaths($target);
            foreach($backupExtraFiles as $alias=>$path){$alias=is_string($alias)?$alias:basename(str_replace('\\','/',$path));BackupFileTree::path($alias);$key='_update_extra/'.$alias;if(isset($paths[$key])){throw new RuntimeException('Duplicate update backup alias.');}$paths[$key]=$path;}
            $backup=$this->backupService->createVerifiedPreUpdateBackup($package->manifest->getVersion(),$paths);$id=$backup['manifest']->getBackupId();
            if(!$backup['verification']->isValid()){throw new RuntimeException('Mandatory pre-update backup verification failed.');}
            $this->checkpoint?->__invoke('backup_verified');
            if(!$this->backupService->verifyBackup($id)->isValid()){throw new RuntimeException('Mandatory backup changed before update.');}
            $expected=[];foreach($backup['manifest']->getContainedFiles() as $name=>$hash){if(str_starts_with($name,'files/')&&!str_starts_with($name,'files/_update_extra/')){$expected[substr($name,6)]=$hash;}}
            $current=$this->targetInventory($target);ksort($expected);ksort($current);
            if($expected!==$current){throw new RuntimeException('Application tree changed after backup; stop writers and retry.');}
            $this->preflightDestinations($target,$package->manifest->getFileChecksums());
            if(!$this->moduleChecker->checkCompatibility($package->manifest->getVersion(),$package->manifest->getRequiredModules())['compatible']){throw new RuntimeException('Module compatibility changed after backup.');}
            $migrations=$session->run($id);
            if(!$this->moduleChecker->checkCompatibility($package->manifest->getVersion(),$package->manifest->getRequiredModules())['compatible']){throw new RuntimeException('Module compatibility changed during migrations.');}
            $applied=0;
            foreach($package->manifest->getFileChecksums() as $relative=>$hash){
                $destination=$target.'/'.$relative;
                if(!is_dir(dirname($destination))&&!mkdir(dirname($destination),0755,true)){throw new RuntimeException('Cannot create update destination parent.');}
                if(!copy($package->directory().'/files/'.$relative,$destination)||!hash_equals($hash,(string)hash_file('sha256',$destination))){throw new RuntimeException('Cannot publish verified update file.');}$applied++;
            }
            $session->complete();
            return ['success'=>true,'target_version'=>$package->manifest->getVersion(),'files_applied'=>$applied,'migrations_run'=>$migrations,'backup_id'=>$id];
        } catch(Throwable $error){$session?->fail();throw $error;}
        finally {try{$session?->close();}finally{$package?->close();}}
    }
    private function targetPaths(string $root): array
    {
        $paths=[];foreach(scandir($root) as $name){if($name!=='.'&&$name!=='..'){BackupFileTree::path($name);$paths[$name]=$root.'/'.$name;}}return $paths;
    }
    private function targetInventory(string $root): array
    {
        $inventory=[];$visit=function(string $path,string $relative)use(&$visit,&$inventory):void{
            BackupFileTree::path($relative);UpdatePackageSnapshot::assertNoLinks($path);
            if(is_dir($path)){$inventory[$relative.'/']=hash('sha256','');foreach(scandir($path) as $name){if($name!=='.'&&$name!=='..'){$visit($path.'/'.$name,$relative.'/'.$name);}}}
            elseif(is_file($path)){$inventory[$relative]=(string)hash_file('sha256',$path);}else{throw new RuntimeException('Unsafe update target entry.');}
        };
        foreach($this->targetPaths($root) as $relative=>$path){$visit($path,$relative);}return $inventory;
    }
    private function preflightDestinations(string $target,array $files): void
    {
        foreach($files as $relative=>$_){$path=$target.'/'.$relative;UpdatePackageSnapshot::assertNoLinks($path);
            if(is_dir($path)){throw new RuntimeException('Update destination collides with an existing directory.');}
            $parent=dirname($path);while($parent!==$target&&strlen($parent)>strlen($target)){if(file_exists($parent)&&!is_dir($parent)){throw new RuntimeException('Update destination parent is a file.');}$parent=dirname($parent);}
        }
    }
}
