<?php
declare(strict_types=1);
namespace Coleza\Domain\Updater;
use Coleza\Domain\Backup\BackupFileTree;
use RuntimeException;

/** Exact inventories include empty directories. All generated working trees are target siblings. */
final class UpdateFileTree
{
    public static function paths(string $root): array
    {
        $paths=[];foreach(scandir($root) as $name){if($name!=='.'&&$name!=='..'){BackupFileTree::path($name);$paths[$name]=$root.'/'.$name;}}return $paths;
    }
    public static function inventory(string $root): array
    {
        $inventory=[];$visit=function(string $path,string $relative)use(&$visit,&$inventory):void{
            BackupFileTree::path($relative);UpdatePackageSnapshot::assertNoLinks($path);
            if(is_dir($path)){$inventory[$relative.'/']=hash('sha256','');foreach(scandir($path) as $name){if($name!=='.'&&$name!=='..'){$visit($path.'/'.$name,$relative.'/'.$name);}}}
            elseif(is_file($path)){$inventory[$relative]=(string)hash_file('sha256',$path);}else{throw new RuntimeException('Unsafe update tree entry.');}
        };
        foreach(self::paths($root) as $relative=>$path){$visit($path,$relative);}ksort($inventory);return $inventory;
    }
    public static function digest(array $inventory): string {ksort($inventory);return hash('sha256',json_encode($inventory,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));}
    public static function prepare(string $target,string $prepared,UpdatePackageSnapshot $package,array $expected): string
    {
        if(file_exists($prepared)||is_link($prepared)||!mkdir($prepared,0700)){throw new RuntimeException('Cannot create exclusive update tree.');}
        foreach(self::inventory($target) as $relative=>$hash){
            $to=$prepared.'/'.rtrim($relative,'/');$from=$target.'/'.rtrim($relative,'/');
            if(str_ends_with($relative,'/')){if(!mkdir($to,0700)){throw new RuntimeException('Cannot prepare update directory.');}}
            else{self::copy($from,$to,$hash);}
        }
        if(self::inventory($target)!==$expected){throw new RuntimeException('Application tree changed while preparing update.');}
        foreach($package->manifest->getFileChecksums() as $relative=>$hash){
            $to=$prepared.'/'.$relative;
            if(!is_dir(dirname($to))&&!mkdir(dirname($to),0700,true)){throw new RuntimeException('Cannot prepare update file parent.');}
            self::copy($package->directory().'/files/'.$relative,$to,$hash);
            $expected[$relative]=$hash;$parent=dirname($relative);
            while($parent!=='.'){$expected[$parent.'/']=hash('sha256','');$parent=dirname($parent);}
        }
        $portable=[];foreach(array_keys($expected) as $path){$key=mb_strtolower(rtrim($path,'/'),'UTF-8');if(isset($portable[$key])){throw new RuntimeException('Prepared tree has a non-portable case/path collision.');}$portable[$key]=true;}
        ksort($expected);if(self::inventory($prepared)!==$expected){throw new RuntimeException('Prepared update tree inventory mismatch.');}
        // Keep original directory permissions, including private config/upload directories.
        foreach(array_reverse(array_keys(self::inventory($target))) as $relative){if(str_ends_with($relative,'/')){chmod($prepared.'/'.rtrim($relative,'/'),fileperms($target.'/'.rtrim($relative,'/'))&0777);}}
        chmod($prepared,fileperms($target)&0777);return self::digest($expected);
    }
    private static function copy(string $from,string $to,string $hash): void
    {
        $mode=is_file($to)?fileperms($to)&0777:fileperms($from)&0777;
        if(!copy($from,$to)||!hash_equals($hash,(string)hash_file('sha256',$to))){throw new RuntimeException('Cannot prepare verified update file.');}
        chmod($to,0600);$handle=fopen($to,'r+b');if($handle===false){throw new RuntimeException('Cannot reopen prepared file.');}
        try{if(!fsync($handle)){throw new RuntimeException('Cannot persist prepared update file.');}}finally{fclose($handle);chmod($to,$mode);}
    }
}
