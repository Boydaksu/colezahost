<?php
declare(strict_types=1);
namespace Coleza\Domain\Backup;

use Coleza\Domain\Backup\Storage\{BackupPackage,BackupStorageAdapterInterface};
use Coleza\Foundation\Database\Connection;
use Coleza\Domain\Vault\{KeyLifecycleService,ApplicationKey,KeyBinding};
use Coleza\Domain\Privacy\Integrity\PrivacyIntegrityVerifier;
use Coleza\Domain\OperationalMode\{OperationalMode,OperationalModeManager};
use RuntimeException;
use Throwable;

/** Offline staged restore. A successful restore never automatically opens customer access. */
final class RestoreWizardService
{
    public function __construct(private string $temporaryExtractDir,private ?BackupEncryptionService $encryptionService=null,
        private ?\Coleza\Domain\Privacy\Tombstone\BackupRestoreReconciliationService $reconciliationService=null,
        private ?\Coleza\Domain\Vault\Encryptor $applicationEncryptor=null,private ?PrivacyIntegrityVerifier $privacyVerifier=null,private ?\Closure $checkpoint=null)
    {
        if((!is_dir($temporaryExtractDir)&&!mkdir($temporaryExtractDir,0700,true))||is_link($temporaryExtractDir)){throw new RuntimeException('Cannot create private restore staging directory.');}
    }
    public function getTemporaryExtractDir(): string { return $this->temporaryExtractDir; }
    public function inspectBackup(string $backupId,BackupStorageAdapterInterface $sourceStorage,?string $decryptionPassphrase=null): array
    {
        BackupPackage::id($backupId);$stage=$this->stage();
        try{[$manifest,$entries]=$this->unpack($backupId,$sourceStorage,$stage,$decryptionPassphrase);
            return ['manifest'=>$manifest,'is_encrypted'=>(bool)($manifest->getMetadata()['encrypted']??false),'files_count'=>count($entries),'db_dump_found'=>isset($entries['database.sql'])];
        }finally{BackupFileTree::removeOwned($stage);}
    }
    /** The target is offline. Replacement of a nonempty database is explicit and reserved for rollback. */
    public function executeRestore(string $backupId,BackupStorageAdapterInterface $sourceStorage,Connection $targetDb,string $targetAppDir,?string $decryptionPassphrase=null,bool $replaceExistingDatabase=false): RestoreResult
    {
        $stage=null;$lock=null;$dbLock=null;$journal=null;$state=[];$executed=0;$files=0;$tables=[];$settings=null;$prepared=null;
        try{
            BackupPackage::id($backupId);
            if($targetDb->getPdo()->inTransaction()||$targetDb->inTransaction()){throw new RuntimeException('Restore requires standalone offline maintenance; caller transaction retained.');}
            $targetAppDir=$this->target($targetAppDir);$journal=RestoreJournal::path($targetAppDir);
            if(is_link($journal.'.lock')){throw new RuntimeException('Unsafe restore lock.');}$lock=fopen($journal.'.lock','c+b');
            if($lock===false||!flock($lock,LOCK_EX|LOCK_NB)){throw new RuntimeException('Another restore owns this target.');}
            $old=RestoreJournal::read($journal);if($old!==null&&$old['state']!=='complete'){throw new RuntimeException('An unfinished restore requires explicit recovery on a fresh target; no automatic replay.');}
            if($targetDb->getDriverName()==='mysql'){
                $name=substr('coleza_restore_'.hash('sha256',(string)$targetDb->getPdo()->query('SELECT DATABASE()')->fetchColumn()),0,64);
                if((int)$targetDb->selectOne('SELECT GET_LOCK(?,0) AS acquired',[$name])['acquired']!==1){throw new RuntimeException('Another restore owns this database.');}$dbLock=$name;
            }
            $stage=$this->stage();[$manifest,$entries]=$this->unpack($backupId,$sourceStorage,$stage,$decryptionPassphrase);$inventory=$manifest->getMetadata()['database']??null;
            $privacy=$this->privacyVerifier?->forDatabase($targetDb)??$this->reconciliationService?->verifier($targetDb)??new PrivacyIntegrityVerifier($targetDb);
            if(is_array($inventory)&&isset($inventory['tables']['users'])){$privacy->assertRestoreInventoryAvailable();}
            if(isset($entries['database.sql'])){
                if(!is_array($inventory)||($inventory['format']??null)!==2||!is_array($inventory['tables']??null)||!is_string($inventory['sql_mode']??null)){throw new RuntimeException('Database backup requires a complete format-2 inventory; legacy packages must be re-exported.');}
                if($targetDb->getDriverName()==='mysql'&&($inventory['time_zone']??null)!=='+00:00'){throw new RuntimeException('Database backup requires an explicit UTC timestamp inventory.');}
                if($inventory!==null&&($inventory['driver']??null)!==$targetDb->getDriverName()){throw new RuntimeException('Backup database driver differs from target.');}
                if((new LogicalDatabaseDump())->tables($targetDb)!==[]&&!$replaceExistingDatabase){throw new RuntimeException('Restore requires a fresh offline database; existing records retained.');}
            }
            $prepared=dirname($targetAppDir).'/.'.basename($targetAppDir).'.restore_'.bin2hex(random_bytes(12));if(!mkdir($prepared,0700)){throw new RuntimeException('Cannot stage target directory.');}
            if(is_dir($targetAppDir)){$this->copyTree($targetAppDir,$prepared);}
            foreach($entries as$name=>$entry){
                if(!str_starts_with($name,'files/')){continue;}$relative=substr($name,6);if($relative===''){continue;}BackupFileTree::path($relative);$destination=$prepared.'/'.$relative;
                if(str_ends_with($name,'/')){if(!is_dir($destination)&&!mkdir($destination,0700,true)){throw new RuntimeException('Cannot stage restored directory.');}}
                else{if(!is_dir(dirname($destination))&&!mkdir(dirname($destination),0700,true)){throw new RuntimeException('Cannot stage restored parent.');}if(is_dir($destination)||!copy($stage.'/extracted/'.$name,$destination)){throw new RuntimeException('Cannot stage restored file.');}chmod($destination,0600);}
            }
            $state=['backup_id'=>$backupId,'state'=>'preparing','statements_executed'=>0,'target'=>$targetAppDir,'previous_directory'=>null];RestoreJournal::write($journal,$state);
            if(isset($entries['database.sql'])){
                $settings=$this->importSettings($targetDb,$inventory);$state['state']='importing';RestoreJournal::write($journal,$state);
                $path=$stage.'/extracted/database.sql';$escapes=$targetDb->getDriverName()==='mysql'&&!str_contains($inventory['sql_mode']??'','NO_BACKSLASH_ESCAPES');
                foreach(SqlStatementReader::file($path,$escapes)as$statement){
                    if(!preg_match('/^(?:DROP TABLE IF EXISTS\s|CREATE TABLE\s|INSERT INTO\s|CREATE (?:UNIQUE )?INDEX\s|DELETE FROM sqlite_sequence\b)/i',$statement)){throw new RuntimeException('Unsupported statement in logical backup dump.');}
                } // Complete lexical and command admission before the first mutation.
                foreach(SqlStatementReader::file($path,$escapes)as$statement){$targetDb->statement($statement);$executed++;if(preg_match('/^CREATE TABLE (?:IF NOT EXISTS )?`?([a-zA-Z0-9_]+)`?/i',$statement,$match)){$tables[]=$match[1];}$this->checkpoint?->__invoke('import_statement',$executed);}
                $state['state']='verifying';$state['statements_executed']=$executed;RestoreJournal::write($journal,$state);
                if(is_array($inventory)){(new RestoredDatabaseVerifier())->verify($targetDb,$inventory,$replaceExistingDatabase);}
            }
            $hasUsers=KeyBinding::tableExists($targetDb,'users');if($hasUsers){$privacy->assertRestoreInventoryAvailable();}
            $privacyResult=$privacy->required()?$privacy->verify():null;
            if(KeyLifecycleService::requiresVerification($targetDb)){
                $key=$this->applicationEncryptor??ApplicationKey::encryptor(ApplicationKey::configured());(new KeyLifecycleService($targetDb,$key,$privacy))->verify();
            }
            $report=null;$reconciler=$this->reconciliationService?->forDatabase($targetDb);
            if($reconciler===null&&($hasUsers||($privacyResult['counts']['tombstones']??0)>0)){$reconciler=$privacy->reconciliation();}
            if($reconciler!==null){$report=$reconciler->reconcileAfterBackupRestore();$reconciler->assertProductionReady($report);}
            (new OperationalModeManager($targetDb))->transitionTo(OperationalMode::RECOVERY,'Restored target awaits explicit activation');
            if($settings!==null){$this->restoreSettings($targetDb,$settings);$settings=null;}
            $state['state']='publishing';$previous=$targetAppDir.'.previous_'.bin2hex(random_bytes(12));$state['previous_directory']=is_dir($targetAppDir)?$previous:null;RestoreJournal::write($journal,$state);
            if(is_dir($targetAppDir)&&!rename($targetAppDir,$previous)){throw new RuntimeException('Cannot preserve previous application directory.');}
            if(!rename($prepared,$targetAppDir)){if(is_dir($previous)&&!is_dir($targetAppDir)){rename($previous,$targetAppDir);}throw new RuntimeException('Cannot publish restored application directory.');}$prepared=null;
            foreach(array_keys($entries)as$name){if(str_starts_with($name,'files/')&&!str_ends_with($name,'/')){$files++;}}
            $state['state']='complete';$state['files_restored']=$files;RestoreJournal::write($journal,$state);
            return new RestoreResult($backupId,true,$executed,$files,array_values(array_unique($tables)),['source_version'=>$manifest->getSourceAppVersion(),'restored_at'=>date('c'),'recovery_required'=>true,'journal'=>$journal,'previous_directory'=>$state['previous_directory']],null,$report);
        }catch(Throwable $error){
            if($journal!==null&&$state!==[]){$state['state']='failed';$state['statements_executed']=$executed;try{RestoreJournal::write($journal,$state);}catch(Throwable){}}
            return new RestoreResult($backupId,false,$executed,$files,array_values(array_unique($tables)),['recovery_required'=>$state!==[],'journal'=>$journal],$error->getMessage());
        }finally{
            if($settings!==null){$this->restoreSettings($targetDb,$settings);}if($dbLock!==null){$targetDb->selectOne('SELECT RELEASE_LOCK(?)',[$dbLock]);}
            if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}if($stage!==null){BackupFileTree::removeOwned($stage);}if($prepared!==null&&is_dir($prepared)){BackupFileTree::removeOwned($prepared);}
        }
    }
    private function stage(): string { $path=rtrim($this->temporaryExtractDir,'/\\').'/restore_'.bin2hex(random_bytes(16));if(!mkdir($path,0700)){throw new RuntimeException('Cannot stage restore.');}return $path; }
    private function unpack(string $id,BackupStorageAdapterInterface $storage,string $stage,?string $passphrase): array
    {
        if(!$storage->retrieve($id,$stage)){throw new RuntimeException('Backup could not be retrieved.');}$archive=$stage.'/'.$id.'.zip';$manifest=BackupManifest::fromArray(BackupPackage::verify($id,$archive,$stage.'/'.$id.'.manifest.json'));
        if((bool)($manifest->getMetadata()['encrypted']??false)){if($passphrase===null){throw new RuntimeException('Decryption passphrase is required for encrypted backup.');}$decrypted=$archive.'.decrypted';($this->encryptionService??new BackupEncryptionService($passphrase))->decryptFile($archive,$decrypted);$archive=$decrypted;}
        $extract=$stage.'/extracted';if(!mkdir($extract,0700)){throw new RuntimeException('Cannot create private extraction directory.');}return [$manifest,(new BackupArchive())->extract($archive,$manifest,$extract)];
    }
    private function target(string $target): string
    {
        if(str_contains($target,'://')||is_link($target)||is_file($target)){throw new RuntimeException('Unsafe restore target.');}$target=rtrim($target,'/\\');$base=basename($target);
        if($base===''||$base==='.'||$base==='..'||!is_dir(dirname($target))){throw new RuntimeException('Restore target requires an existing parent directory.');}
        $target=str_replace('\\','/',(string)realpath(dirname($target))).'/'.$base;$temporary=str_replace('\\','/',(string)realpath($this->temporaryExtractDir));
        if($target===$temporary||str_starts_with($temporary,$target.'/')){throw new RuntimeException('Restore target cannot contain its staging directory.');}return $target;
    }
    private function copyTree(string $source,string $destination): void
    {
        foreach(scandir($source)as$name){if($name==='.'||$name==='..'){continue;}$from=$source.'/'.$name;$to=$destination.'/'.$name;if(is_link($from)){throw new RuntimeException('Restore target contains a symlink.');}
            if(is_dir($from)){if(!mkdir($to,0700)){throw new RuntimeException('Cannot stage existing target directory.');}$this->copyTree($from,$to);}elseif(!is_file($from)||!copy($from,$to)){throw new RuntimeException('Cannot preserve existing target file.');}}
    }
    private function importSettings(Connection $db,?array $inventory): array
    {
        $pdo=$db->getPdo();if($db->getDriverName()==='mysql'){$prior=$db->selectOne('SELECT @@SESSION.foreign_key_checks AS fk,@@SESSION.sql_mode AS mode,@@character_set_client AS client,@@character_set_connection AS connection,@@character_set_results AS results,@@collation_connection AS collation,@@time_zone AS tz');$pdo->exec("SET SESSION time_zone='+00:00'");$pdo->exec('SET NAMES utf8mb4');$pdo->exec('SET SESSION FOREIGN_KEY_CHECKS=0');$statement=$pdo->prepare('SET SESSION sql_mode=?');$statement->execute([$inventory['sql_mode']??$prior['mode']]);return $prior;}
        $prior=['fk'=>(int)$pdo->query('PRAGMA foreign_keys')->fetchColumn()];$pdo->exec('PRAGMA foreign_keys=OFF');return $prior;
    }
    private function restoreSettings(Connection $db,array $prior): void
    {
        $pdo=$db->getPdo();if($db->getDriverName()==='mysql'){$statement=$pdo->prepare('SET SESSION sql_mode=?');$statement->execute([$prior['mode']]);$pdo->exec('SET SESSION FOREIGN_KEY_CHECKS='.(int)$prior['fk']);$statement=$pdo->prepare('SET character_set_client=?,character_set_connection=?,character_set_results=?,collation_connection=?');$statement->execute([$prior['client'],$prior['connection'],$prior['results'],$prior['collation']]);$statement=$pdo->prepare('SET SESSION time_zone=?');$statement->execute([$prior['tz']]);}else{$pdo->exec('PRAGMA foreign_keys='.(int)$prior['fk']);}
    }
}
