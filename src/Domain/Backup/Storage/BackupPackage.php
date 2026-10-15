<?php
declare(strict_types=1);
namespace Coleza\Domain\Backup\Storage;

final class BackupPackage
{
    public static function id(string $id): void
    {
        if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/D',$id)){throw new \RuntimeException('Invalid backup identifier.');}
    }
    public static function verify(string $id,string $archive,string $manifest): array
    {
        self::id($id);
        foreach([$archive,$manifest]as$file){if(is_link($file)||!is_file($file)||!is_readable($file)){throw new \RuntimeException('Backup archive and manifest must be readable regular files.');}}
        clearstatcache(true,$archive);clearstatcache(true,$manifest);
        if(filesize($manifest)>4194304){throw new \RuntimeException('Backup manifest exceeds the supported size.');}
        try{$data=json_decode((string)file_get_contents($manifest),true,flags:JSON_THROW_ON_ERROR);}catch(\JsonException $error){throw new \RuntimeException('Invalid backup manifest JSON.',previous:$error);}
        if(!is_array($data)||($data['backup_id']??null)!==$id||!is_int($data['file_size_bytes']??null)||$data['file_size_bytes']<=0||$data['file_size_bytes']!==filesize($archive)||!is_string($data['sha256_checksum']??null)||!preg_match('/^[a-f0-9]{64}$/D',$data['sha256_checksum'])||!hash_equals($data['sha256_checksum'],(string)hash_file('sha256',$archive))){throw new \RuntimeException('Tamper detection failed: backup identity, size or checksum does not match its manifest.');}
        return $data;
    }
}
