<?php
declare(strict_types=1);
namespace Coleza\Domain\Backup;

/** A journal survives target-directory swaps and process death. It never contains dump content or secrets. */
final class RestoreJournal
{
    public static function path(string $target): string { return dirname($target).'/.'.basename($target).'.restore-state.json'; }
    public static function read(string $path): ?array
    {
        if(!file_exists($path)&&!is_link($path)){return null;}
        if(is_link($path)||!is_file($path)||filesize($path)>65536){throw new \RuntimeException('Restore journal is unsafe; recovery required.');}
        $state=json_decode((string)file_get_contents($path),true,flags:JSON_THROW_ON_ERROR);
        if(!is_array($state)||!in_array($state['state']??null,['preparing','importing','verifying','publishing','complete','failed'],true)){throw new \RuntimeException('Restore journal is invalid; recovery required.');}return $state;
    }
    public static function write(string $path,array $state): void
    {
        if(is_link($path)){throw new \RuntimeException('Unsafe restore journal path.');}
        $temporary=$path.'.'.bin2hex(random_bytes(12)).'.tmp';$output=fopen($temporary,'xb');
        if($output===false){throw new \RuntimeException('Cannot create restore journal.');}chmod($temporary,0600);
        try{$bytes=json_encode($state+['updated_at'=>date('c')],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);if(fwrite($output,$bytes)!==strlen($bytes)||!fflush($output)||!fsync($output)){throw new \RuntimeException('Cannot persist restore journal.');}fclose($output);$output=null;
            if(!rename($temporary,$path)){throw new \RuntimeException('Cannot publish restore journal.');}
        }finally{if(is_resource($output)){fclose($output);}if(is_file($temporary)){unlink($temporary);}}
    }
}
