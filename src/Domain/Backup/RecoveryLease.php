<?php
declare(strict_types=1);
namespace Coleza\Domain\Backup;

use Coleza\Foundation\Database\Connection;
use Coleza\Domain\Updater\UpdatePackageSnapshot;
use RuntimeException;

/** A live capability for the same offline target/PDO; shared by update and its rollback. */
final class RecoveryLease
{
    private mixed $file=null;
    private mixed $sqliteFile=null;
    private ?string $databaseLock=null;
    private ?string $database=null;
    public function __construct(private Connection $db,private string $target)
    {
        try{
            if($db->inTransaction()){throw new RuntimeException('Recovery/update cannot own a caller transaction.');}
            $path=RestoreJournal::path($target).'.lock';UpdatePackageSnapshot::assertNoLinks($path);
            $this->file=fopen($path,'c+b');
            if($this->file===false||!flock($this->file,LOCK_EX|LOCK_NB)){throw new RuntimeException('Update/recovery lock is busy.');}chmod($path,0600);
            if($db->getDriverName()==='mysql'){
                if((int)$db->getPdo()->query('SELECT @@autocommit')->fetchColumn()!==1){throw new RuntimeException('Update requires autocommit, without a caller transaction.');}
                $database=(string)$db->getPdo()->query('SELECT DATABASE()')->fetchColumn();
                $this->database=$database;
                $name='colezahost:migrations:'.substr(hash('sha256',$database),0,32);
                if((int)($db->selectOne('SELECT GET_LOCK(?,0) AS acquired',[$name])['acquired']??0)!==1){throw new RuntimeException('Database migration lock is busy.');}
                $this->databaseLock=$name;
            }elseif($db->getDriverName()==='sqlite'){
                foreach($db->select('PRAGMA database_list') as $row){
                    if($row['name']!=='main'||$row['file']===''){continue;}
                    $path=$row['file'].'.offline.lock';UpdatePackageSnapshot::assertNoLinks($path);$this->sqliteFile=fopen($path,'c+b');
                    if($this->sqliteFile===false||!flock($this->sqliteFile,LOCK_EX|LOCK_NB)){throw new RuntimeException('SQLite database recovery lock is busy.');}chmod($path,0600);
                }
            }
        }catch(\Throwable $error){$this->close();throw $error;}
    }
    public function assertOwns(Connection $db,string $target): void
    {
        if(!is_resource($this->file)||$db->getPdo()!==$this->db->getPdo()||$target!==$this->target){throw new RuntimeException('Live matching update recovery lease is required.');}
        if($this->database!==null&&(string)$db->getPdo()->query('SELECT DATABASE()')->fetchColumn()!==$this->database){throw new RuntimeException('Update/recovery database changed while its lease was held.');}
    }
    public function close(): void
    {
        try{if($this->databaseLock!==null){$name=$this->databaseLock;$this->databaseLock=null;$this->db->selectOne('SELECT RELEASE_LOCK(?)',[$name]);}}
        finally{if(is_resource($this->sqliteFile)){flock($this->sqliteFile,LOCK_UN);fclose($this->sqliteFile);}$this->sqliteFile=null;if(is_resource($this->file)){flock($this->file,LOCK_UN);fclose($this->file);}$this->file=null;}
    }
}
