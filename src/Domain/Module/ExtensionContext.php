<?php
declare(strict_types=1);
namespace Coleza\Domain\Module;
use Coleza\Foundation\Database\Connection;
use Coleza\Domain\Backup\{BackupFileTree,RecoveryLease};
use Coleza\Domain\Updater\{UpdateFileTree,UpdatePackageManifest,UpdatePackageSnapshot};
use Coleza\Domain\Vault\VaultService;
use RuntimeException;

/** Host-owned capability contract. No PDO, Core service container or arbitrary SQL is exposed.
 * Packaged PHP is not implicitly required here. Trusted same-process adapters are not OS sandboxes.
 */
final class ExtensionContext
{
    private function __construct(private Connection $db,private string $root,private string $id,private string $coreVersion,private ?VaultService $vault,private ?\Closure $network,private ?RecoveryLease $healthLease) {}
    public static function installed(Connection $db,string $root,string $id,string $coreVersion,?VaultService $vault=null,?\Closure $network=null): self
    {
        $self=new self($db,$root,$id,$coreVersion,$vault,$network,null);$self->record();return $self;
    }
    public static function health(Connection $db,string $root,string $id,string $coreVersion,RecoveryLease $lease,?VaultService $vault=null,?\Closure $network=null): self
    {
        $lease->assertOwns($db,$root);$self=new self($db,$root,$id,$coreVersion,$vault,$network,$lease);$self->record();return $self;
    }
    private function record(): array
    {
        $row=$this->db->selectOne('SELECT * FROM installed_modules WHERE module_id=?',[$this->id]);
        if($row===null||($this->healthLease===null&&(int)$row['is_enabled']!==1)){throw new RuntimeException('Extension is not enabled.');}
        $this->healthLease?->assertOwns($this->db,$this->root);
        if($this->healthLease===null){
            $guard=$this->db->selectOne('SELECT setting_value FROM system_settings WHERE setting_key=?',['system.update_migration_guard']);
            $journal=\Coleza\Domain\Backup\RestoreJournal::read(\Coleza\Domain\Backup\RestoreJournal::path($this->root));
            if(($guard!==null&&(ExtensionPackage::decode($guard['setting_value'])['state']??null)!=='complete')||($journal!==null&&$journal['state']!=='complete')){throw new RuntimeException('Extension runtime is blocked during recovery.');}
        }
        $data=ExtensionPackage::decode($row['manifest']);$manifest=ModuleManifest::fromArray($data);$proof=$data['_extension']??null;
        if(!is_array($proof)||($proof['state']??null)!=='installed'||$manifest->id!==$this->id||$row['name']!==$manifest->name||$row['version']!==$manifest->version||$row['type']!==$manifest->type||$manifest->extensionApiVersion!=='1.0.0'){throw new RuntimeException('Extension installation receipt is invalid.');}
        $version=$this->db->selectOne('SELECT setting_value FROM system_settings WHERE setting_key=?',['system.core_version'])['setting_value']??$this->coreVersion;
        if(UpdatePackageManifest::compareVersions($version,$manifest->minCoreVersion)<0||($manifest->maxCoreVersion!==null&&UpdatePackageManifest::compareVersions($version,$manifest->maxCoreVersion)>0)){throw new RuntimeException('Extension core compatibility changed.');}
        foreach($manifest->dependencies as $id=>$required){$dependency=$this->db->selectOne('SELECT version,is_enabled FROM installed_modules WHERE module_id=?',[$id]);if($dependency===null||(int)$dependency['is_enabled']!==1||UpdatePackageManifest::compareVersions($dependency['version'],$required)<0){throw new RuntimeException('Extension dependency is unavailable.');}}
        $permissions=$proof['permissions']??null;$expected=$manifest->permissions;sort($expected);
        if(!is_array($permissions)){throw new RuntimeException('Extension permission receipt missing.');}$actual=$permissions;sort($actual);if($actual!==$expected){throw new RuntimeException('Extension permission grant disagrees with descriptor.');}ExtensionPackage::permissions($actual);
        if(in_array('vault:read',$actual,true)){if($this->vault===null){throw new RuntimeException('Declared extension Vault contract is not configured.');}$this->vault->assertConnection($this->db);}
        foreach($actual as $permission){if(str_starts_with($permission,'network:')&&$this->network===null){throw new RuntimeException('Declared extension network contract is not configured.');}}
        $directory=$this->root.'/'.$this->id;UpdatePackageSnapshot::assertNoLinks($directory);
        if(!is_dir($directory)||!hash_equals((string)($proof['tree_sha256']??''),UpdateFileTree::digest(UpdateFileTree::inventory($directory)))){throw new RuntimeException('Installed extension file integrity failed.');}
        $descriptor=ModuleManifest::fromArray(ExtensionPackage::decode(file_get_contents($directory.'/extension.json')));
        if($descriptor->toArray()!==$manifest->toArray()){throw new RuntimeException('Installed extension descriptor binding failed.');}
        $prefix=ExtensionMigrationPlan::prefix($this->id);$ledger=$prefix.'migration_ledger';
        if(($proof['migration_ledger']??null)!==$ledger||!is_array($proof['tables']??null)){throw new RuntimeException('Extension schema receipt is invalid.');}
        $requirements=[$ledger=>['migration','sha256']];foreach($proof['tables'] as $table=>$columns){if(!str_starts_with($table,$prefix)||!is_array($columns)){throw new RuntimeException('Extension schema namespace mismatch.');}$requirements[$table]=$columns;}
        if(!\Coleza\Foundation\Database\PdoSchema::useInstalledSchema($this->db,$requirements)){throw new RuntimeException('Extension installed schema is missing.');}
        $files=ExtensionPackage::decode(file_get_contents($directory.'/extension.json'))['migration_files'];$expected=[];foreach($files as $file){$expected[$file]=hash_file('sha256',$directory.'/'.$file);}
        $actual=[];foreach($this->db->select('SELECT migration,sha256 FROM `'.$ledger.'`') as $entry){$actual[$entry['migration']]=$entry['sha256'];}ksort($expected);ksort($actual);if($expected!==$actual){throw new RuntimeException('Extension migration ledger integrity failed.');}
        return [$manifest,$proof];
    }
    private function permit(string $permission,bool $write=false): array
    {
        [$manifest,$proof]=$this->record();if(!in_array($permission,$proof['permissions'],true)||($write&&$this->healthLease!==null)){throw new RuntimeException('Extension permission denied: '.$permission);}return $proof;
    }
    private function table(string $local): string
    {
        [, $proof]=$this->record();$name=ExtensionMigrationPlan::prefix($this->id).ExtensionMigrationPlan::local($local);
        if(!isset($proof['tables'][$name])){throw new RuntimeException('Extension table was not declared by its migration.');}return $name;
    }
    public function rows(string $table,int $limit=100,int $offset=0): array {$this->permit('database:read');if($limit<1||$limit>100||$offset<0){throw new RuntimeException('Invalid bounded extension pagination.');}return $this->db->select('SELECT * FROM `'.$this->table($table).'` LIMIT '.$limit.' OFFSET '.$offset);}
    public function count(string $table): int {$this->permit('database:read');return (int)$this->db->getPdo()->query('SELECT COUNT(*) FROM `'.$this->table($table).'`')->fetchColumn();}
    public function insert(string $table,array $values): int
    {
        $proof=$this->permit('database:write',true);$name=$this->table($table);if($values===[]||array_diff(array_keys($values),$proof['tables'][$name])!==[]){throw new RuntimeException('Undeclared extension column.');}
        $bytes=0;foreach($values as $value){if(!is_scalar($value)&&$value!==null){throw new RuntimeException('Invalid extension row value.');}$bytes+=is_string($value)?strlen($value):16;if($bytes>1048576){throw new RuntimeException('Extension row byte budget exceeded.');}}
        return (int)$this->db->insert($name,$values);
    }
    private function dataPath(string $relative,bool $create=false): string
    {
        BackupFileTree::path($relative);$root=dirname($this->root).'/extension-data/'.$this->id;UpdatePackageSnapshot::assertNoLinks($root.'/'.$relative);
        if($create&&!is_dir(dirname($root.'/'.$relative))&&!mkdir(dirname($root.'/'.$relative),0700,true)){throw new RuntimeException('Cannot create extension storage.');}return $root.'/'.$relative;
    }
    public function writeFile(string $relative,string $bytes): void
    {
        $this->permit('storage:write',true);if(strlen($bytes)>1048576){throw new RuntimeException('Extension storage write budget exceeded.');}$path=$this->dataPath($relative,true);$tmp=$path.'.'.bin2hex(random_bytes(12)).'.tmp';
        try{if(file_put_contents($tmp,$bytes,LOCK_EX)!==strlen($bytes)){throw new RuntimeException('Cannot write extension storage.');}chmod($tmp,0600);if(!rename($tmp,$path)){throw new RuntimeException('Cannot publish extension storage.');}}finally{if(is_file($tmp)){unlink($tmp);}}
    }
    public function readFile(string $relative): string {$this->permit('storage:read');$path=$this->dataPath($relative);if(!is_file($path)||filesize($path)>1048576){throw new RuntimeException('Extension storage file unavailable.');}return file_get_contents($path);}
    private function setting(string $key): string {if(!preg_match('/^[a-z][a-z0-9_.-]{0,39}$/D',$key)||preg_match('/password|secret|token|credential/i',$key)){throw new RuntimeException('Use scoped Vault for secrets; invalid extension setting.');}return 'extension.'.$this->id.'.'.$key;}
    public function getSetting(string $key): ?string {$this->permit('settings:read');return $this->db->selectOne('SELECT setting_value FROM system_settings WHERE setting_key=?',[$this->setting($key)])['setting_value']??null;}
    public function setSetting(string $key,string $value): void
    {
        $this->permit('settings:write',true);if(strlen($value)>65536){throw new RuntimeException('Extension setting budget exceeded.');}$suffix=$this->db->getDriverName()==='mysql'?' ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)':' ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value';$this->db->statement('INSERT INTO system_settings (setting_key,setting_value) VALUES (?,?)'.$suffix,[$this->setting($key),$value]);
    }
    public function secret(string $key): ?string {$this->permit('vault:read');if($this->vault===null){throw new RuntimeException('Scoped Vault is not configured.');}$this->vault->assertConnection($this->db);return $this->vault->getSecret('module:'.$this->id,$key);}
    public function request(string $url): mixed
    {
        if(preg_match('/[\x00-\x20\x7f\\\\]/',$url)){throw new RuntimeException('Invalid extension network URL characters.');}
        $parts=parse_url($url);if(!is_array($parts)||($parts['scheme']??null)!=='https'||!isset($parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])){throw new RuntimeException('Extension network URL must use an exact approved HTTPS origin without userinfo/fragment.');}
        if(str_starts_with(rawurldecode($parts['path']??'/'),'//')){throw new RuntimeException('Network paths cannot replace the approved origin.');}
        $host=strtolower($parts['host']).(isset($parts['port'])?':'.$parts['port']:'');$this->permit('network:https://'.$host);if($this->network===null){throw new RuntimeException('A host-owned no-redirect HTTPS transport must be configured.');}
        // Only GET is exposed. The host transport receives the approved origin separately and must not redirect.
        return ($this->network)('https://'.$host,($parts['path']??'/').(isset($parts['query'])?'?'.$parts['query']:''));
    }
    public function route(\Coleza\Foundation\Http\Router $router,string $method,string $path,\Closure $handler): void
    {
        $this->permit('routes:register');if($this->healthLease!==null||!in_array($method,['GET','POST','PUT','PATCH','DELETE'],true)||!preg_match('#^/[a-zA-Z0-9_/{}/-]*$#D',$path)){throw new RuntimeException('Invalid extension route.');}
        $wrapped=function(...$args)use($handler){$this->permit('routes:register');return $handler(...$args);};$router->{strtolower($method)}('/extensions/'.$this->id.$path,$wrapped);
    }
}
