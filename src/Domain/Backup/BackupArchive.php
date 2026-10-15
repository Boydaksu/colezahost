<?php
declare(strict_types=1);
namespace Coleza\Domain\Backup;

use RuntimeException;
use ZipArchive;

/** Exact manifest membership and streaming extraction into a newly owned private directory. */
final class BackupArchive
{
    public function extract(string $archive,BackupManifest $manifest,string $destination): array
    {
        if(!in_array($manifest->getType(),['full','database','db','files','pre_update'],true)){throw new RuntimeException('Unknown backup scope.');}
        $zip=new ZipArchive();if($zip->open($archive,ZipArchive::RDONLY)!==true){throw new RuntimeException('Cannot open backup ZIP.');}
        try{
            if($zip->numFiles>100000){throw new RuntimeException('Backup archive has too many entries.');}
            $expected=$manifest->getContainedFiles();$entries=[];$seen=[];$size=0;
            for($i=0;$i<$zip->numFiles;$i++){
                $stat=$zip->statIndex($i);if($stat===false){throw new RuntimeException('Cannot inspect backup ZIP entry.');}$name=$stat['name'];BackupFileTree::path($name);
                if($name!=='database.sql'&&!str_starts_with($name,'files/')){throw new RuntimeException('Unexpected backup archive entry.');}
                $key=mb_strtolower(rtrim($name,'/'),'UTF-8');if(isset($seen[$key])){throw new RuntimeException('Duplicate or case-colliding ZIP entry.');}$seen[$key]=true;
                $opsys=0;$attrs=0;if(!$zip->getExternalAttributesIndex($i,$opsys,$attrs)){throw new RuntimeException('Cannot inspect ZIP entry type.');}
                $type=($attrs>>16)&0170000;$directory=str_ends_with($name,'/');
                if($opsys===ZipArchive::OPSYS_UNIX&&!in_array($type,[0,$directory?0040000:0100000],true)){throw new RuntimeException('Backup symlink or special ZIP entry is forbidden.');}
                $size+=$stat['size'];if($stat['size']<0||$size>10*1024*1024*1024||($directory&&$stat['size']!==0)){throw new RuntimeException('Backup uncompressed size exceeds safety limit.');}
                if(!isset($expected[$name])||!is_string($expected[$name])||!preg_match('/^[a-f0-9]{64}$/D',$expected[$name])){throw new RuntimeException('Backup entry is absent from its checksum inventory.');}
                $entries[$name]=$stat;
            }
            if(count($entries)!==count($expected)){throw new RuntimeException('Backup checksum inventory does not match ZIP entries.');}
            foreach($entries as$name=>$stat){
                $parent=dirname(rtrim($name,'/'));while($parent!=='.'){
                    if(isset($entries[$parent])){throw new RuntimeException('Backup file/directory path collision.');}$parent=dirname($parent);
                }
                $target=$destination.'/'.$name;
                if(str_ends_with($name,'/')){
                    if(!hash_equals(hash('sha256',''),$expected[$name])){throw new RuntimeException('Backup directory checksum mismatch.');}
                    if(!is_dir($target)&&!mkdir($target,0700,true)){throw new RuntimeException('Cannot stage backup directory.');}continue;
                }
                if(!is_dir(dirname($target))&&!mkdir(dirname($target),0700,true)){throw new RuntimeException('Cannot stage backup parent directory.');}
                $input=$zip->getStream($name);$output=fopen($target,'xb');if($input===false||$output===false){throw new RuntimeException('Cannot stream backup archive entry.');}
                $hash=hash_init('sha256');$written=0;
                try{while(!feof($input)){$chunk=fread($input,65536);if($chunk===false){throw new RuntimeException('Cannot read backup archive entry.');}$written+=strlen($chunk);if($written>$stat['size']||fwrite($output,$chunk)!==strlen($chunk)){throw new RuntimeException('Backup archive extraction is incomplete.');}hash_update($hash,$chunk);}
                    if($written!==$stat['size']||!hash_equals($expected[$name],hash_final($hash))||!fflush($output)||!fsync($output)){throw new RuntimeException('Backup entry checksum or size mismatch.');}
                }finally{fclose($input);fclose($output);}chmod($target,0600);
            }
            $dbFound=isset($entries['database.sql']);
            if(in_array($manifest->getType(),['full','database','db','pre_update'],true)&&!$dbFound){throw new RuntimeException('Backup database scope is missing its dump.');}
            if($manifest->getType()==='files'&&$dbFound){throw new RuntimeException('Files-only backup unexpectedly includes a database dump.');}
            return $entries;
        }finally{$zip->close();}
    }
}
