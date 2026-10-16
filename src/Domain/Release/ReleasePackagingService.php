<?php
declare(strict_types=1);
namespace Coleza\Domain\Release;

use Coleza\Domain\Backup\BackupFileTree;
use Coleza\Domain\Updater\{PackageSignatureVerifier,UpdatePackageException,UpdatePackageManifest,UpdatePackageSnapshot};
use RuntimeException;
use Throwable;
use ZipArchive;

/** Distribution packages and staged updates share one manifest/files contract. */
final class ReleasePackagingService
{
    public function __construct(private string $workingDirectory)
    {
        if(!is_dir($workingDirectory)&&!mkdir($workingDirectory,0700,true)){throw new RuntimeException('Cannot create release working directory.');}
        UpdatePackageSnapshot::assertNoLinks($workingDirectory);
    }
    public function buildPackage(array $filesToBundle,string $version,string $releaseNotes,string $signingPrivateKey,?string $outputZipPath=null,string $minCurrentVersion='1.0.0',array $requiredModules=[],array $migrations=[]): ReleasePackageArtifact
    {
        UpdatePackageManifest::assertVersion($version);UpdatePackageManifest::assertVersion($minCurrentVersion);
        if($filesToBundle===[]){throw new RuntimeException('Release requires explicit payload files.');}
        foreach($filesToBundle as $name=>$path){if(!is_string($name)||!is_string($path)||!is_file($path)){throw new RuntimeException('Release payload must map relative names to regular source files.');}BackupFileTree::path($name);}
        $stage=rtrim($this->workingDirectory,'/\\').'/pkg_build_'.bin2hex(random_bytes(12));
        if(!mkdir($stage,0700)){throw new RuntimeException('Cannot create private release snapshot.');}
        $zip=new ZipArchive();$opened=false;
        try {
            $files=(new BackupFileTree())->snapshot($filesToBundle,$stage);$checksums=[];$total=0;
            foreach($files as $name=>$path){if($path===null){throw new RuntimeException('Release payload cannot contain source directories.');}$checksums[$name]=(string)hash_file('sha256',$path);$total+=filesize($path);}
            if($total>1073741824){throw new RuntimeException('Release payload exceeds the 1 GiB update limit.');}ksort($checksums);
            $manifest=new UpdatePackageManifest($version,$minCurrentVersion,$releaseNotes,$checksums,$requiredModules,$migrations);
            $data=UpdatePackageManifest::fromArray($manifest->toArray())->toArray();$created=$data['released_at'];
            $raw=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $sig=PackageSignatureVerifier::signPayload($raw,$signingPrivateKey);$lines=[];foreach($checksums as $name=>$hash){$lines[]=$hash.'  '.$name;}
            foreach(['manifest.json'=>$raw,'manifest.sig'=>$sig,'CHECKSUMS.sha256'=>implode("\n",$lines)."\n",'RELEASE_NOTES.md'=>$releaseNotes] as $name=>$bytes){if(file_put_contents($stage.'/'.$name,$bytes,LOCK_EX)!==strlen($bytes)){throw new RuntimeException('Cannot write release metadata.');}chmod($stage.'/'.$name,0600);}
            $zipPath=$outputZipPath??rtrim($this->workingDirectory,'/\\').'/coleza-host-v'.$version.'.zip';
            if($zip->open($zipPath,ZipArchive::CREATE|ZipArchive::EXCL)!==true){throw new RuntimeException('Cannot create release archive; existing archives are retained.');}$opened=true;
            foreach($files as $name=>$path){if(!$zip->addFile($path,'files/'.$name)){throw new RuntimeException('Cannot add frozen release file.');}}
            foreach(['manifest.json','manifest.sig','CHECKSUMS.sha256','RELEASE_NOTES.md'] as $name){if(!$zip->addFile($stage.'/'.$name,$name)){throw new RuntimeException('Cannot add signed release metadata.');}}
            if(!$zip->close()){throw new RuntimeException('Cannot finalize release archive.');}$opened=false;
            return new ReleasePackageArtifact($version,$zipPath,$stage.'/manifest.json',$stage.'/manifest.sig',$stage.'/CHECKSUMS.sha256',$stage.'/RELEASE_NOTES.md',(string)hash_file('sha256',$zipPath),(int)filesize($zipPath),count($checksums),$checksums,$data,$created);
        } catch(Throwable $error) {
            if($opened){$zip->close();}$opened=false;BackupFileTree::removeOwned($stage);throw $error;
        }
    }
    public function verifyPackageArchive(string $zipPath,string $expectedPublicKey): ReleaseVerificationReport
    {
        $package=null;
        try {
            $package=UpdatePackageSnapshot::fromArchive($zipPath,new PackageSignatureVerifier($expectedPublicKey));
            return new ReleaseVerificationReport($package->manifest->getVersion(),true,true,metadata:['files_checked'=>count($package->manifest->getFileChecksums()),'verified_at'=>date('c')]);
        } catch(Throwable $error) {
            return new ReleaseVerificationReport('unknown',$error instanceof UpdatePackageException&&$error->signatureValid,false,$error instanceof UpdatePackageException?$error->tampered:[],$error instanceof UpdatePackageException?$error->missing:[],$error->getMessage());
        } finally {$package?->close();}
    }
}
