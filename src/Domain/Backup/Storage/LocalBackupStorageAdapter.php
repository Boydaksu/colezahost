<?php
declare(strict_types=1);
namespace Coleza\Domain\Backup\Storage;

use Coleza\Domain\Backup\BackupDestinationType;
use RuntimeException;

final class LocalBackupStorageAdapter implements BackupStorageAdapterInterface
{
    public function __construct(private string $storageDirectory)
    {
        if(!is_dir($storageDirectory)&&!mkdir($storageDirectory,0700,true)){throw new RuntimeException('Cannot create private backup storage.');}
        if(is_link($storageDirectory)||($root=realpath($storageDirectory))===false||!is_writable($root)){throw new RuntimeException('Backup storage must be a writable directory.');}$this->storageDirectory=$root;
    }
    public function getType(): BackupDestinationType{return BackupDestinationType::LOCAL;}
    public function store(string $backupId,string $localArchivePath,string $localManifestPath): bool
    {
        BackupPackage::verify($backupId,$localArchivePath,$localManifestPath);
        return $this->locked(function()use($backupId,$localArchivePath,$localManifestPath):bool{
            [$archive,$manifest]=$this->paths($backupId);
            if(file_exists($archive)||file_exists($manifest)||is_link($archive)||is_link($manifest)){
                BackupPackage::verify($backupId,$archive,$manifest);
                if(hash_equals((string)hash_file('sha256',$archive),(string)hash_file('sha256',$localArchivePath))&&hash_equals((string)hash_file('sha256',$manifest),(string)hash_file('sha256',$localManifestPath))){return true;}
                throw new RuntimeException('Backup ID already exists with different content; existing package retained.');
            }
            $prefix=$this->storageDirectory.'/.upload_'.bin2hex(random_bytes(12));$stageArchive=$prefix.'.zip';$stageManifest=$prefix.'.json';
            try{
                $this->copyFile($localArchivePath,$stageArchive);$this->copyFile($localManifestPath,$stageManifest);BackupPackage::verify($backupId,$stageArchive,$stageManifest);
                if(!rename($stageArchive,$archive)){throw new RuntimeException('Cannot publish backup archive.');}
                if(!rename($stageManifest,$manifest)){throw new RuntimeException('Cannot publish backup manifest; source package retained.');}
                return true;
            }finally{foreach([$stageArchive,$stageManifest]as$file){if(is_file($file)&&!is_link($file)){unlink($file);}}}
        });
    }
    public function retrieve(string $backupId,string $localDestinationDir): bool
    {
        return $this->locked(function()use($backupId,$localDestinationDir):bool{
            [$archive,$manifest]=$this->paths($backupId);if(!is_file($archive)||!is_file($manifest)){return false;}BackupPackage::verify($backupId,$archive,$manifest);
            if(realpath($localDestinationDir)===$this->storageDirectory){return true;}
            return (new self($localDestinationDir))->store($backupId,$archive,$manifest);
        });
    }
    public function delete(string $backupId): bool
    {
        return $this->locked(function()use($backupId):bool{
            [$archive,$manifest]=$this->paths($backupId);if(!is_file($archive)||!is_file($manifest)){return false;}BackupPackage::verify($backupId,$archive,$manifest);
            if(!unlink($manifest)||!unlink($archive)){throw new RuntimeException('Backup deletion did not finish.');}return true;
        });
    }
    public function exists(string $backupId): bool
    {
        return $this->locked(function()use($backupId):bool{[$archive,$manifest]=$this->paths($backupId);if(!is_file($archive)||!is_file($manifest)){return false;}BackupPackage::verify($backupId,$archive,$manifest);return true;});
    }
    public function listBackups(): array
    {
        return $this->locked(function():array{$ids=[];foreach(glob($this->storageDirectory.'/*.manifest.json')?:[]as$file){$id=basename($file,'.manifest.json');BackupPackage::id($id);[$archive,$manifest]=$this->paths($id);if(is_file($archive)){BackupPackage::verify($id,$archive,$manifest);$ids[]=$id;}}sort($ids);return $ids;});
    }
    private function paths(string $id): array
    {
        BackupPackage::id($id);return [$this->storageDirectory.'/'.$id.'.zip',$this->storageDirectory.'/'.$id.'.manifest.json'];
    }
    private function locked(callable $operation): mixed
    {
        $path=$this->storageDirectory.'/.publish.lock';if(is_link($path)){throw new RuntimeException('Unsafe backup publication lock.');}$lock=fopen($path,'c+b');
        if($lock===false){throw new RuntimeException('Cannot open backup publication lock.');}
        try{if(!flock($lock,LOCK_EX)){throw new RuntimeException('Cannot lock backup publication.');}return $operation();}finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    private function copyFile(string $source,string $target): void
    {
        $input=fopen($source,'rb');$output=fopen($target,'xb');if($input===false||$output===false){if(is_resource($input)){fclose($input);}if(is_resource($output)){fclose($output);}throw new RuntimeException('Cannot open backup staging file.');}
        try{if(stream_copy_to_stream($input,$output)===false||!fflush($output)||!fsync($output)){throw new RuntimeException('Cannot persist backup staging file.');}}finally{fclose($input);fclose($output);}
    }
}
