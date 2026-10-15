<?php
declare(strict_types=1);
namespace Coleza\Domain\Backup;

use RuntimeException;

final class BackupFileTree
{
    public static function path(string $path): string
    {
        if($path===''||!preg_match('//u',$path)||preg_match('/[\\\\:\x00-\x1f\x7f]/',$path)||str_starts_with($path,'/')||strlen($path)>1024){throw new RuntimeException('Unsafe backup relative path.');}
        foreach(explode('/',rtrim($path,'/'))as$part){if($part===''||$part==='.'||$part==='..'||preg_match('/[. ]$/D',$part)||preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i',$part)){throw new RuntimeException('Unsafe or non-portable backup path component.');}}
        return $path;
    }

    /** Copy stable source bytes before ZIP opens its deferred input handles. @return array<string,string|null> */
    public function snapshot(array $paths,string $stage,array $excluded=[]): array
    {
        $entries=[];$seen=[];$exclude=[];$total=0;
        foreach($excluded as$path){$resolved=realpath($path);if($resolved!==false){$exclude[]=str_replace('\\','/',$resolved);}}
        $visit=function(string $source,string $relative,bool $explicit=false)use(&$visit,&$entries,&$seen,&$total,$stage,$exclude):void{
            self::path($relative);if(is_link($source)){throw new RuntimeException('Backup source symlinks are forbidden.');}
            $resolved=realpath($source);if($resolved===false||!is_readable($source)){throw new RuntimeException('Every requested backup source must exist and be readable.');}
            $normalized=str_replace('\\','/',$resolved);
            foreach($exclude as$skip){if((!$explicit||is_dir($source))&&($normalized===$skip||str_starts_with($normalized,$skip.'/'))){if($explicit){throw new RuntimeException('Backup directory cannot be its own working directory.');}return;}}
            $key=mb_strtolower(rtrim($relative,'/'),'UTF-8');if(isset($seen[$key])){throw new RuntimeException('Backup paths collide; provide distinct explicit relative aliases.');}$seen[$key]=true;
            if(count($seen)>100000){throw new RuntimeException('Backup file count exceeds safety limit.');}
            if(is_dir($source)){
                $entries[rtrim($relative,'/').'/']=null;$names=scandir($source);if($names===false){throw new RuntimeException('Cannot enumerate backup directory.');}
                foreach($names as$name){if($name!=='.'&&$name!=='..'){$visit($source.'/'.$name,rtrim($relative,'/').'/'.$name);}}return;
            }
            if(!is_file($source)){throw new RuntimeException('Backup supports only regular files and directories.');}
            $target=$stage.'/file_'.count($entries);$before=stat($source);
            $total+=$before['size'];if($total>10*1024*1024*1024){throw new RuntimeException('Backup sources exceed the 10 GiB uncompressed limit.');}
            if(!copy($source,$target)){throw new RuntimeException('Cannot snapshot backup source file.');}chmod($target,0600);clearstatcache(true,$source);$after=stat($source);
            if($before['size']!==$after['size']||$before['mtime']!==$after['mtime']||!hash_equals((string)hash_file('sha256',$source),(string)hash_file('sha256',$target))){throw new RuntimeException('Backup source changed while being read; retry with writers stopped.');}
            $entries[$relative]=$target;
            if(count($entries)>100000){throw new RuntimeException('Backup file count exceeds safety limit.');}
        };
        foreach($paths as$alias=>$path){if(!is_string($path)){throw new RuntimeException('Invalid backup source.');}$visit($path,is_string($alias)?$alias:basename(rtrim(str_replace('\\','/',$path),'/')),true);}
        return $entries;
    }

    public static function removeOwned(string $directory): void
    {
        if(is_link($directory)||is_file($directory)){unlink($directory);return;}if(!is_dir($directory)){return;}
        foreach(scandir($directory)as$name){if($name!=='.'&&$name!=='..'){self::removeOwned($directory.'/'.$name);}}rmdir($directory);
    }
}
