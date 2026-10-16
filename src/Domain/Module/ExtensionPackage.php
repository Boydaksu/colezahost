<?php
declare(strict_types=1);
namespace Coleza\Domain\Module;
use Coleza\Domain\Updater\{PackageSignatureVerifier,UpdatePackageSnapshot,UpdateHealthGate};
use RuntimeException;

/** Uses the existing signed manifest/files quarantine. Nothing in a package is required or booted. */
final class ExtensionPackage
{
    private function __construct(public readonly UpdatePackageSnapshot $snapshot,public readonly ModuleManifest $manifest,public readonly array $migrationFiles,public readonly string $digest) {}
    public static function open(string $source,PackageSignatureVerifier $verifier): self
    {
        if(is_file($source)){
            UpdatePackageSnapshot::assertNoLinks($source);$zip=new \ZipArchive();if($zip->open($source,\ZipArchive::RDONLY)!==true){throw new RuntimeException('Cannot open extension archive.');}
            try{
                if($zip->numFiles>5000){throw new RuntimeException('Extension ZIP entry budget exceeded.');}$total=0;
                for($i=0;$i<$zip->numFiles;$i++){$stat=$zip->statIndex($i);if($stat===false){throw new RuntimeException('Cannot inspect extension ZIP.');}$total+=$stat['size'];if($total>67108864||($stat['size']>1048576&&$stat['size']/max(1,$stat['comp_size'])>100)){throw new RuntimeException('Extension ZIP size/compression budget exceeded.');}}
            }finally{$zip->close();}
        }else{
            UpdatePackageSnapshot::assertNoLinks($source);$count=0;$bytes=0;
            $walk=function(string $path)use(&$walk,&$count,&$bytes):void{
                if(++$count>5000||is_link($path)){throw new RuntimeException('Unsafe or excessive extension source entries.');}
                if(is_dir($path)){foreach(scandir($path) as $name){if($name!=='.'&&$name!=='..'){$walk($path.'/'.$name);}}}
                elseif(is_file($path)){$bytes+=filesize($path);if($bytes>67108864){throw new RuntimeException('Extension source size budget exceeded.');}}
                else{throw new RuntimeException('Extension source must contain regular files.');}
            };$walk($source);
        }
        $snapshot=is_file($source)?UpdatePackageSnapshot::fromArchive($source,$verifier):UpdatePackageSnapshot::fromDirectory($source,$verifier);
        try{
            UpdateHealthGate::assertPayloadSyntax($snapshot);
            if(isset($snapshot->manifest->getFileChecksums()['manifest.json'])){throw new RuntimeException('Legacy discovery manifest cannot bypass the extension descriptor.');}
            if(!isset($snapshot->manifest->getFileChecksums()['extension.json'])){throw new RuntimeException('Signed extension.json is required.');}
            $raw=file_get_contents($snapshot->directory().'/files/extension.json');if(strlen($raw)>65536){throw new RuntimeException('Extension descriptor budget exceeded.');}
            $data=self::decode($raw);
            $allowed=['id','name','version','type','min_core_version','max_core_version','extension_api_version','capabilities','permissions','dependencies','description','author','migration_files'];
            if(array_diff(array_keys($data),$allowed)!==[]){throw new RuntimeException('Unknown extension descriptor field.');}
            foreach(['id','name','version','type','min_core_version','max_core_version','extension_api_version'] as $key){if(!is_string($data[$key]??null)){throw new RuntimeException('Strict extension descriptor requires '.$key);}}
            foreach(['capabilities','permissions','dependencies','migration_files'] as $key){if(!is_array($data[$key]??null)){throw new RuntimeException('Strict extension descriptor requires '.$key);}}
            $manifest=ModuleManifest::fromArray($data);self::permissions($manifest->permissions);
            if($manifest->version!==$snapshot->manifest->getVersion()||$manifest->minCoreVersion!==$snapshot->manifest->getMinCurrentVersion()||$manifest->dependencies!==$snapshot->manifest->getRequiredModules()||$snapshot->manifest->getMigrations()!==[]){throw new RuntimeException('Extension descriptor disagrees with signed envelope.');}
            $migrations=$data['migration_files'];if(!array_is_list($migrations)||count($migrations)>100||count(array_unique($migrations,SORT_REGULAR))!==count($migrations)){throw new RuntimeException('Invalid ordered extension migration list.');}
            foreach($migrations as $file){if(!is_string($file)||!preg_match('#^migrations/[a-z0-9_-]+\.json$#D',$file)||!isset($snapshot->manifest->getFileChecksums()[$file])){throw new RuntimeException('Extension migrations must be explicitly checksummed declarative JSON.');}}
            foreach(array_keys($snapshot->manifest->getFileChecksums()) as $file){if(str_starts_with($file,'migrations/')&&!in_array($file,$migrations,true)){throw new RuntimeException('Unlisted extension migration payload.');}}
            return new self($snapshot,$manifest,$migrations,hash('sha256',$snapshot->manifestBytes));
        }catch(\Throwable $error){$snapshot->close();throw $error;}
    }
    public static function decode(string $bytes): array
    {
        $data=json_decode($bytes,true,64,JSON_THROW_ON_ERROR);if(!is_array($data)||!str_starts_with(ltrim($bytes),'{')){throw new RuntimeException('Extension JSON object required.');}
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\[\]:,]/s',$bytes,$matches);$stack=[];$tokens=$matches[0];
        foreach($tokens as $i=>$token){if($token==='{'||$token==='['){$stack[]=[];}elseif($token==='}'||$token===']'){array_pop($stack);}elseif(str_starts_with($token,'"')&&($tokens[$i+1]??null)===':'){$key=json_decode($token,true,flags:JSON_THROW_ON_ERROR);$last=count($stack)-1;if(isset($stack[$last][$key])){throw new RuntimeException('Duplicate extension JSON key.');}$stack[$last][$key]=true;}}
        return $data;
    }
    public static function permissions(array $permissions): void
    {
        foreach($permissions as $permission){
            if(in_array($permission,['database:read','database:write','storage:read','storage:write','settings:read','settings:write','vault:read','routes:register'],true)){continue;}
            if(!preg_match('#^network:https://[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?(?::([1-9][0-9]{0,4}))?$#D',$permission,$match)||str_contains($permission,'..')||(isset($match[1])&&(int)$match[1]>65535)){throw new RuntimeException('Unknown or overbroad extension permission.');}
        }
    }
    public function close(): void {$this->snapshot->close();}
}
