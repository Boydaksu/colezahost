<?php
declare(strict_types=1);
namespace Coleza\Domain\Updater;

use Coleza\Domain\Backup\{RestoreJournal,RecoveryLease};
use Coleza\Domain\OperationalMode\{OperationalMode,OperationalModeManager};
use Coleza\Foundation\Database\{Connection,Migrator,PdoSchema};
use RuntimeException;
use Throwable;

/** Owns update admission locks and durable migration receipts, outside the replaceable app tree.
 * In-flight/failed DDL is never automatically replayed. Recovery is an explicit later operation.
 */
final class UpdateMigrationSession
{
    private array $locks=[];
    private RecoveryLease $lease;
    private string $journal;
    private string $registry;
    private string $identity;
    private array $plan=[];
    private ?array $state=null;
    private static ?\WeakMap $memoryDatabases=null;

    public function __construct(private Connection $db,private ?UpdatePackageSnapshot $package,string $target,string $store,?string $sourceVersion=null)
    {
        $this->journal=RestoreJournal::path($target);
        try {
            $this->lease=new RecoveryLease($db,$target);
            $prior=RestoreJournal::read($this->journal);
            if($package!==null&&$prior!==null&&$prior['state']!=='complete'){throw new RuntimeException('Unfinished update or restore requires explicit recovery.');}
            if($db->getDriverName()==='mysql'){
                $database=(string)$db->getPdo()->query('SELECT DATABASE()')->fetchColumn();
                $server=$db->selectOne('SELECT @@hostname AS host, @@port AS port');
                $this->identity=hash('sha256',json_encode([$server,$database],JSON_THROW_ON_ERROR));
                $this->registry=$store.'/.update-migrations/'.$this->identity;
            }else{
                $main=array_values(array_filter($db->select('PRAGMA database_list'),static fn(array $row):bool=>$row['name']==='main'))[0];
                if($main['file']!==''){
                    $file=realpath($main['file']);
                    if($file===false){throw new RuntimeException('Cannot identify SQLite migration database.');}
                    if(UpdatePackageSnapshot::overlaps($file,$target)){throw new RuntimeException('SQLite update database must be outside the replaceable application tree.');}
                    $this->identity=hash('sha256',$file);
                    // Shared by different targets and backup stores using this physical database.
                    $this->registry=$file.'.update-migrations';
                }else{
                    self::$memoryDatabases??=new \WeakMap();$pdo=$db->getPdo();
                    self::$memoryDatabases[$pdo]??=bin2hex(random_bytes(16));
                    $this->identity=self::$memoryDatabases[$pdo];
                    $this->registry=$store.'/.update-migrations/'.$this->identity;
                }
            }
            UpdatePackageSnapshot::assertNoLinks($this->registry);
            if(!is_dir($this->registry)&&!mkdir($this->registry,0700,true)){throw new RuntimeException('Cannot create migration receipt directory.');}
            $this->fileLock($this->registry.'/database.lock');
            if($package===null){
                if($prior===null||($prior['purpose']??null)!=='update'||($prior['database']??null)!==$this->identity||($prior['target']??null)!==$target||!is_string($prior['backup_id']??null)||!is_string($prior['source_version']??null)){
                    throw new RuntimeException('Bound update journal is required for recovery.');
                }
                $this->state=$prior;return;
            }
            if($sourceVersion!==null&&isset($prior['installed_version'])&&$prior['installed_version']!==$sourceVersion){throw new RuntimeException('Installed version differs from updater source version.');}
            // A failed attempt on another application target sharing this DB must also block updates.
            foreach(glob($this->registry.'/*.json')?:[] as $receipt){
                $record=RestoreJournal::read($receipt);
                if(($record['state']??null)!=='complete'){throw new RuntimeException('Incomplete migration receipt requires explicit recovery.');}
            }
            if(PdoSchema::useInstalledSchema($db,['system_settings'=>['setting_key','setting_value']])){
                $installed=$db->selectOne('SELECT setting_value FROM system_settings WHERE setting_key=?',['system.core_version']);
                if($sourceVersion!==null&&$installed!==null&&$installed['setting_value']!==$sourceVersion){throw new RuntimeException('Installed database version differs from updater source version.');}
                $guard=$db->selectOne('SELECT setting_value FROM system_settings WHERE setting_key=?',['system.update_migration_guard']);
                if($guard!==null){
                    try{$guardState=json_decode($guard['setting_value'],true,flags:JSON_THROW_ON_ERROR);}catch(Throwable $error){throw new RuntimeException('Invalid update guard requires recovery.',0,$error);}
                    if(!is_array($guardState)||($guardState['state']??null)!=='complete'){throw new RuntimeException('Database update guard requires explicit recovery.');}
                }
            }
            if($package->manifest->getMigrations()===[]){return;}
            if(!PdoSchema::useInstalledSchema($db,['migrations'=>['id','migration','batch','applied_at']]+OperationalModeManager::schemaRequirements())){
                throw new RuntimeException('Update requires installed migration and operational mode schema.');
            }
            $ran=[];foreach((new Migrator($db))->getRanMigrations() as $name){$ran[strtolower($name)]=$name;}
            $names=[];
            foreach($package->manifest->getMigrations() as $relative){
                $name=pathinfo($relative,PATHINFO_FILENAME);$key=strtolower($name);
                if(!preg_match('/^[A-Za-z0-9_][A-Za-z0-9_-]{0,254}$/D',$name)){throw new RuntimeException('Unsafe migration ledger name.');}
                if(isset($names[$key])){throw new RuntimeException('Duplicate migration ledger name.');}$names[$key]=true;
                $file=$package->directory().'/files/'.$relative;$hash=$package->manifest->getFileChecksums()[$relative];
                try{token_get_all((string)file_get_contents($file),TOKEN_PARSE);}catch(\ParseError $error){throw new RuntimeException('Invalid migration PHP syntax.',0,$error);}
                $receipt=$this->registry.'/'.hash('sha256',$key).'.json';$record=RestoreJournal::read($receipt);
                $already=isset($ran[$key]);
                if($already||$record!==null){
                    if(!$already||$record===null||($record['state']??null)!=='complete'||($record['migration']??null)!==$name||($record['sha256']??null)!==$hash||($record['database']??null)!==$this->identity||$ran[$key]!==$name){
                        throw new RuntimeException('Migration identity disagrees with installed ledger or signed bytes.');
                    }
                }
                $this->plan[]=compact('name','file','hash','receipt','already');
            }
        }catch(Throwable $error){$this->close();throw $error;}
    }

    public function lease(): RecoveryLease {return $this->lease;}
    public function context(): ?array {return $this->state;}
    public function begin(string $backupId,string $sourceVersion,string $target,string $prepared,string $previous,string $backupHash,string $manifestHash): void
    {
        (new OperationalModeManager($this->db))->transitionTo(OperationalMode::RECOVERY,'Signed update awaits verified activation');
        $this->state=['state'=>'preparing','purpose'=>'update','database'=>$this->identity,'target'=>$target,
            'manifest_sha256'=>hash('sha256',$this->package->manifestBytes),'backup_id'=>$backupId,'backup_sha256'=>$backupHash,'backup_manifest_sha256'=>$manifestHash,
            'source_version'=>$sourceVersion,'target_version'=>$this->package->manifest->getVersion(),'installed_version'=>$sourceVersion,
            'prepared_directory'=>$prepared,'previous_directory'=>$previous,'migrations_run'=>0];
        $this->writeDatabaseGuard('importing');RestoreJournal::write($this->journal,$this->state);
    }
    public function step(string $state,array $extra=[]): void
    {
        if($this->state===null){throw new RuntimeException('Update journal has not begun.');}
        $this->state=array_replace($this->state,$extra,['state'=>$state]);RestoreJournal::write($this->journal,$this->state);
    }
    /** Intent is durable before require, including top-level PHP. */
    public function run(string $backupId): int
    {
        if($this->state===null){throw new RuntimeException('Verified update context must begin before migrations.');}
        $this->lease->assertOwns($this->db,$this->state['target']);
        $this->step('importing');
        $count=0;
        foreach($this->plan as $entry){
            if($entry['already']){continue;}
            $record=['state'=>'importing','purpose'=>'update_migration','database'=>$this->identity,'migration'=>$entry['name'],'sha256'=>$entry['hash'],'backup_id'=>$backupId];
            RestoreJournal::write($entry['receipt'],$record);
            // Recheck frozen bytes immediately before require; no path from the live source is loaded.
            if(!hash_equals($entry['hash'],(string)hash_file('sha256',$entry['file']))){throw new RuntimeException('Frozen migration bytes changed.');}
            $applied=(new Migrator($this->db))->migrateOrderedFiles([$entry['file']]);
            $this->lease->assertOwns($this->db,$this->state['target']);
            if($this->db->inTransaction()||($this->db->getDriverName()==='mysql'&&(int)$this->db->getPdo()->query('SELECT @@autocommit')->fetchColumn()!==1)){
                throw new RuntimeException('Migration left an open transaction; explicit recovery required.');
            }
            if($applied!==[$entry['name']]){throw new RuntimeException('Migration ledger changed during update.');}
            $record['state']='complete';RestoreJournal::write($entry['receipt'],$record);
            $this->state['migrations_run']=++$count;RestoreJournal::write($this->journal,$this->state);
        }
        $this->state['state']='publishing';RestoreJournal::write($this->journal,$this->state);
        return $count;
    }
    public function complete(): void
    {
        $this->lease->assertOwns($this->db,$this->state['target']);
        $this->persistVersion($this->state['target_version']);
        $this->writeDatabaseGuard('complete');$this->step('complete',['installed_version'=>$this->state['target_version'],'outcome'=>'applied']);
    }
    public function finishRollback(): void
    {
        // Only this attempt's receipts are removed, after DB/files/key/privacy restore succeeded.
        foreach(glob($this->registry.'/*.json')?:[] as $file){
            $record=RestoreJournal::read($file);
            if(($record['backup_id']??null)===$this->state['backup_id']&&!unlink($file)){throw new RuntimeException('Cannot reconcile rolled-back migration receipt.');}
        }
        $this->persistVersion($this->state['source_version']);$this->writeDatabaseGuard('complete');
        $this->step('complete',['installed_version'=>$this->state['source_version'],'outcome'=>'rolled_back']);
    }
    private function persistVersion(string $version): void
    {
        UpdatePackageManifest::assertVersion($version);
        $suffix=$this->db->getDriverName()==='mysql'?' ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)':' ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value';
        $this->db->statement('INSERT INTO system_settings (setting_key,setting_value) VALUES (?,?)'.$suffix,['system.core_version',$version]);
    }
    public function fail(): void
    {
        if($this->state!==null){$this->step('failed');}
    }
    private function writeDatabaseGuard(string $state): void
    {
        // Independent of target and backup directory: another updater cannot bypass partial DDL
        // by choosing a different filesystem journal/receipt location for the same database.
        $suffix=$this->db->getDriverName()==='mysql'?' ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)':' ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value';
        $this->db->statement('INSERT INTO system_settings (setting_key,setting_value) VALUES (?,?)'.$suffix,
            ['system.update_migration_guard',json_encode(['state'=>$state,'backup_id'=>$this->state['backup_id'],'manifest_sha256'=>$this->state['manifest_sha256']],JSON_THROW_ON_ERROR)]);
    }
    private function fileLock(string $path): void
    {
        UpdatePackageSnapshot::assertNoLinks($path);
        $handle=fopen($path,'c+b');
        if($handle===false){throw new RuntimeException('Cannot open update lock.');}
        if(!flock($handle,LOCK_EX|LOCK_NB)){fclose($handle);throw new RuntimeException('Update lock is busy.');}
        chmod($path,0600);$this->locks[]=$handle;
    }
    public function close(): void
    {
        try{foreach(array_reverse($this->locks) as $handle){flock($handle,LOCK_UN);fclose($handle);}$this->locks=[];}
        finally{if(isset($this->lease)){$this->lease->close();}}
    }
}
