<?php
declare(strict_types=1);
namespace Coleza\Domain\Updater;

use Coleza\Domain\Backup\BackupFileTree;
use RuntimeException;
use Throwable;
use ZipArchive;

/** Only frozen, signed and exactly inventoried bytes leave this admission boundary. */
final class UpdatePackageSnapshot
{
    private const MAX_BYTES=1073741824;
    private function __construct(private string $directory,public readonly UpdatePackageManifest $manifest,public readonly string $manifestBytes) {}
    public function directory(): string {return $this->directory;}
    public function close(): void {BackupFileTree::removeOwned($this->directory);}
    public static function assertNoLinks(string $path): void
    {
        $path=rtrim(str_replace('\\','/',$path),'/');
        do {if(is_link($path)){throw new RuntimeException('Update paths cannot contain symlinks.');}$parent=dirname($path);if($parent===$path){break;}$path=$parent;} while($path!=='.'&&$path!=='');
    }
    public static function overlaps(string $a,string $b): bool
    {
        $normalize=static fn(string $p):string=>rtrim(str_replace('\\','/',PHP_OS_FAMILY==='Windows'?mb_strtolower($p,'UTF-8'):$p),'/');
        $a=$normalize($a);$b=$normalize($b);return $a===$b||str_starts_with($a,$b.'/')||str_starts_with($b,$a.'/');
    }
    private static function stage(): string
    {
        $path=sys_get_temp_dir().'/coleza_update_snapshot_'.bin2hex(random_bytes(16));
        if(!mkdir($path,0700)){throw new RuntimeException('Cannot create private update staging.');}return $path;
    }
    private static function read(string $path,int $limit): string
    {
        self::assertNoLinks($path);
        if(!is_file($path)||!is_readable($path)){throw new RuntimeException('Missing or unreadable update metadata.');}
        $stream=fopen($path,'rb');if($stream===false){throw new RuntimeException('Cannot read update metadata.');}
        try{$bytes=stream_get_contents($stream,$limit+1);if($bytes===false||strlen($bytes)>$limit){throw new RuntimeException('Update metadata exceeds safety limit.');}return $bytes;}finally{fclose($stream);}
    }
    private static function decode(string $bytes): array
    {
        $object=json_decode($bytes,false,64,JSON_THROW_ON_ERROR);
        if(!$object instanceof \stdClass){throw new RuntimeException('Update manifest must be a JSON object.');}
        // Valid JSON is tokenized only to reject duplicate keys, including escaped equivalents.
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\[\]:,]/s',$bytes,$matches);
        $stack=[];$tokens=$matches[0];
        foreach($tokens as $i=>$token){
            if($token==='{'||$token==='['){$stack[]=[];}
            elseif($token==='}'||$token===']'){array_pop($stack);}
            elseif(str_starts_with($token,'"')&&($tokens[$i+1]??null)===':'){
                $key=json_decode($token,true,flags:JSON_THROW_ON_ERROR);$last=count($stack)-1;
                if(isset($stack[$last][$key])){throw new RuntimeException('Duplicate JSON manifest key.');}$stack[$last][$key]=true;
            }
        }
        return json_decode($bytes,true,64,JSON_THROW_ON_ERROR);
    }
    public static function fromDirectory(string $source,PackageSignatureVerifier $verifier): self
    {
        $stage=null;$signed=false;
        try{
            self::assertNoLinks($source);$source=realpath($source);
            if($source===false||!is_dir($source)){throw new RuntimeException('Staging directory does not exist.');}
            $raw=self::read($source.'/manifest.json',4194304);$sig=trim(self::read($source.'/manifest.sig',16384));
            if(!$verifier->verify($raw,$sig)){throw new RuntimeException('Cryptographic signature verification failed.');}$signed=true;
            $manifest=UpdatePackageManifest::fromArray(self::decode($raw));$expected=$manifest->getFileChecksums();
            $allowed=['manifest.json','manifest.sig','files'];
            foreach(['CHECKSUMS.sha256','RELEASE_NOTES.md'] as $name){
                if(file_exists($source.'/'.$name)||is_link($source.'/'.$name)){
                    $wanted=$manifest->getReleaseNotes();
                    if($name==='CHECKSUMS.sha256'){$lines=[];$sorted=$expected;ksort($sorted);foreach($sorted as $file=>$hash){$lines[]=$hash.'  '.$file;}$wanted=implode("\n",$lines)."\n";}
                    if(self::read($source.'/'.$name,4194304)!==$wanted){throw new RuntimeException('Unsigned release metadata differs from its signed manifest.');}$allowed[]=$name;
                }
            }
            if(array_diff(scandir($source),array_merge(['.','..'],$allowed))!==[]){throw new RuntimeException('Unexpected update root entry.');}
            self::assertNoLinks($source.'/files');if(!is_dir($source.'/files')){throw new RuntimeException('Missing update files directory.');}
            $actual=[];$entries=0;$bytes=0;
            $walk=function(string $dir,string $prefix='')use(&$walk,&$actual,&$entries,&$bytes,$expected):void{
                foreach(scandir($dir) as $name){if($name==='.'||$name==='..'){continue;}$relative=$prefix.$name;BackupFileTree::path($relative);$path=$dir.'/'.$name;
                    if(++$entries>100000||is_link($path)){throw new RuntimeException('Unsafe or excessive update entries.');}
                    if(is_dir($path)){
                        $used=false;foreach($expected as $file=>$_){if(str_starts_with($file,$relative.'/')){$used=true;break;}}
                        if(!$used){throw new RuntimeException('Unlisted update directory.');}$walk($path,$relative.'/');
                    }elseif(is_file($path)&&is_readable($path)){$bytes+=filesize($path);if($bytes>self::MAX_BYTES){throw new RuntimeException('Update exceeds the 1 GiB payload limit.');}$actual[]=$relative;}
                    else{throw new RuntimeException('Update payload must contain regular files.');}
                }
            };$walk($source.'/files');$missing=array_values(array_diff(array_keys($expected),$actual));
            if($missing!==[]){throw new UpdatePackageException('Missing update files.',true,missing:$missing);}
            if(array_diff($actual,array_keys($expected))!==[]){throw new RuntimeException('Unlisted update payload file.');}
            $stage=self::stage();mkdir($stage.'/files',0700);$frozenBytes=0;
            foreach($expected as $relative=>$hash){
                $input=$source.'/files/'.$relative;$output=$stage.'/files/'.$relative;self::assertNoLinks($input);
                if(!is_dir(dirname($output))&&!mkdir(dirname($output),0700,true)){throw new RuntimeException('Cannot stage update parent.');}
                $in=fopen($input,'rb');$out=fopen($output,'xb');if($in===false||$out===false){if(is_resource($in)){fclose($in);}if(is_resource($out)){fclose($out);}throw new RuntimeException('Cannot freeze update payload.');}
                try{$copied=stream_copy_to_stream($in,$out,self::MAX_BYTES+1);if($copied===false||$copied>self::MAX_BYTES||!fflush($out)||!fsync($out)){throw new RuntimeException('Cannot persist bounded update payload.');}}finally{fclose($in);fclose($out);}chmod($output,0600);
                $frozenBytes+=$copied;
                if($frozenBytes>self::MAX_BYTES||!hash_equals($hash,(string)hash_file('sha256',$output))){throw new UpdatePackageException('Update payload checksum mismatch.',true,[$relative]);}
            }
            return new self($stage,$manifest,$raw);
        }catch(Throwable $error){if($stage!==null){BackupFileTree::removeOwned($stage);}if($error instanceof UpdatePackageException){throw $error;}throw new UpdatePackageException($error->getMessage(),$signed,$signed?[$error->getMessage()]:[]);}
    }
    /** Bounded extraction into owned quarantine; the same directory admission follows. */
    public static function fromArchive(string $archive,PackageSignatureVerifier $verifier): self
    {
        self::assertNoLinks($archive);$zip=new ZipArchive();$stage=self::stage();$opened=false;
        try{
            if($zip->open($archive,ZipArchive::RDONLY)!==true){throw new RuntimeException('Cannot open update archive.');}$opened=true;
            if($zip->numFiles>100000){throw new RuntimeException('Too many update ZIP entries.');}$seen=[];$bytes=0;
            for($i=0;$i<$zip->numFiles;$i++){
                $stat=$zip->statIndex($i);if($stat===false){throw new RuntimeException('Cannot inspect update ZIP entry.');}$name=$stat['name'];BackupFileTree::path($name);
                $key=mb_strtolower(rtrim($name,'/'),'UTF-8');if(isset($seen[$key])){throw new RuntimeException('Duplicate or case-colliding update ZIP entry.');}$seen[$key]=true;
                $dir=str_ends_with($name,'/');$os=0;$attr=0;if(!$zip->getExternalAttributesIndex($i,$os,$attr)){throw new RuntimeException('Cannot inspect update ZIP type.');}
                if($os===ZipArchive::OPSYS_UNIX&&!in_array(($attr>>16)&0170000,[0,$dir?0040000:0100000],true)){throw new RuntimeException('Update ZIP symlink or special entry forbidden.');}
                $bytes+=$stat['size'];if($stat['size']<0||$bytes>self::MAX_BYTES||($dir&&$stat['size']!==0)){throw new RuntimeException('Update ZIP uncompressed size exceeds limit.');}
                if($name!=='files/'&&!str_starts_with($name,'files/')&&!in_array($name,['manifest.json','manifest.sig','CHECKSUMS.sha256','RELEASE_NOTES.md'],true)){throw new RuntimeException('Unexpected update ZIP root entry.');}
                if(!str_starts_with($name,'files/')&&$stat['size']>($name==='manifest.sig'?16384:4194304)){throw new RuntimeException('Update ZIP metadata exceeds limit.');}
                $target=$stage.'/'.$name;
                $parent=dirname($target);while(strlen($parent)>=strlen($stage)){if(file_exists($parent)&&!is_dir($parent)){throw new RuntimeException('Update ZIP parent path collision.');}if($parent===$stage){break;}$parent=dirname($parent);}
                if(file_exists($target)){if(!$dir||!is_dir($target)){throw new RuntimeException('Update ZIP file/directory collision.');}}
                if($dir){if(!is_dir($target)&&!mkdir($target,0700,true)){throw new RuntimeException('Cannot stage update ZIP directory.');}continue;}
                if(!is_dir(dirname($target))&&!mkdir(dirname($target),0700,true)){throw new RuntimeException('Update ZIP path collision.');}
                $in=$zip->getStream($name);$out=fopen($target,'xb');if($in===false||$out===false){if(is_resource($in)){fclose($in);}if(is_resource($out)){fclose($out);}throw new RuntimeException('Cannot stage update ZIP entry.');}
                try{$n=stream_copy_to_stream($in,$out,$stat['size']+1);if($n!==$stat['size']||!fflush($out)||!fsync($out)){throw new RuntimeException('Incomplete update ZIP entry.');}}finally{fclose($in);fclose($out);}chmod($target,0600);
            }
            return self::fromDirectory($stage,$verifier);
        }catch(Throwable $error){if($error instanceof UpdatePackageException){throw $error;}throw new UpdatePackageException($error->getMessage());}
        finally{if($opened){$zip->close();}BackupFileTree::removeOwned($stage);}
    }
}
