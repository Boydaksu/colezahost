<?php
declare(strict_types=1);
namespace Coleza\Domain\Updater;

use Coleza\Domain\Backup\RestoreJournal;
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
    private ?string $databaseLock=null;
    private string $journal;
    private string $registry;
    private string $identity;
    private array $plan=[];
    private ?array $state=null;
    private static ?\WeakMap $memoryDatabases=null;

    public function __construct(private Connection $db,private UpdatePackageSnapshot $package,string $target,string $store)
    {
        $this->journal=RestoreJournal::path($target);
        try {
            if($db->inTransaction()){throw new RuntimeException('Update cannot run inside a caller transaction.');}
            $this->fileLock($this->journal.'.lock');
            $prior=RestoreJournal::read($this->journal);
            if($prior!==null&&$prior['state']!=='complete'){throw new RuntimeException('Unfinished update or restore requires explicit recovery.');}
            if($db->getDriverName()==='mysql'){
                if((int)$db->getPdo()->query('SELECT @@autocommit')->fetchColumn()!==1){throw new RuntimeException('Update requires autocommit, without a caller transaction.');}
                $database=(string)$db->getPdo()->query('SELECT DATABASE()')->fetchColumn();
                $lock='colezahost:migrations:'.substr(hash('sha256',$database),0,32);
                $row=$db->selectOne('SELECT GET_LOCK(?, 0) AS acquired',[$lock]);
                if((int)($row['acquired']??0)!==1){throw new RuntimeException('Database migration lock is busy.');}
                $this->databaseLock=$lock;
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
            // A failed attempt on another application target sharing this DB must also block updates.
            foreach(glob($this->registry.'/*.json')?:[] as $receipt){
                $record=RestoreJournal::read($receipt);
                if(($record['state']??null)!=='complete'){throw new RuntimeException('Incomplete migration receipt requires explicit recovery.');}
            }
            if(PdoSchema::useInstalledSchema($db,['system_settings'=>['setting_key','setting_value']])){
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

    /** Persist intent before loading PHP or executing SQL, including top-level migration code. */
    public function run(string $backupId): int
    {
        if($this->plan===[]){return 0;}
        (new OperationalModeManager($this->db))->transitionTo(OperationalMode::RECOVERY,'Signed update migration in progress');
        $this->state=['state'=>'importing','purpose'=>'update','database'=>$this->identity,'manifest_sha256'=>hash('sha256',$this->package->manifestBytes),'backup_id'=>$backupId,'migrations_run'=>0];
        $this->writeDatabaseGuard('importing');
        RestoreJournal::write($this->journal,$this->state);
        $count=0;
        foreach($this->plan as $entry){
            if($entry['already']){continue;}
            $record=['state'=>'importing','purpose'=>'update_migration','database'=>$this->identity,'migration'=>$entry['name'],'sha256'=>$entry['hash'],'backup_id'=>$backupId];
            RestoreJournal::write($entry['receipt'],$record);
            // Recheck frozen bytes immediately before require; no path from the live source is loaded.
            if(!hash_equals($entry['hash'],(string)hash_file('sha256',$entry['file']))){throw new RuntimeException('Frozen migration bytes changed.');}
            $applied=(new Migrator($this->db))->migrateOrderedFiles([$entry['file']]);
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
        if($this->state!==null){$this->writeDatabaseGuard('complete');$this->state['state']='complete';RestoreJournal::write($this->journal,$this->state);}
    }
    public function fail(): void
    {
        if($this->state!==null){$this->state['state']='failed';RestoreJournal::write($this->journal,$this->state);}
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
        try{if($this->databaseLock!==null){$lock=$this->databaseLock;$this->databaseLock=null;$this->db->selectOne('SELECT RELEASE_LOCK(?) AS released',[$lock]);}}
        finally{foreach(array_reverse($this->locks) as $handle){flock($handle,LOCK_UN);fclose($handle);}$this->locks=[];}
    }
}
