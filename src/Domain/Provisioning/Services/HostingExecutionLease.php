<?php
declare(strict_types=1);
namespace Coleza\Domain\Provisioning\Services;
use Coleza\Foundation\Database\Connection;
use RuntimeException;
/** Session/process lifetime mutex, independent of transactions and without TTL-based remote replay. */
final class HostingExecutionLease
{
    /** @var array<string,self> */
    private static array $held=[];
    private mixed $file=null;
    private ?string $databaseLock=null;
    private bool $closed=false;
    private function __construct(private Connection $db,private string $key,private string $database) {}
    public static function acquire(Connection $db,int $serviceId):self
    {
        if($serviceId<1||$db->getPdo()->inTransaction()){throw new \LogicException('Hosting execution requires a service and no caller transaction.');}
        $driver=$db->getDriverName();$database='';$path=null;
        if($driver==='mysql'){
            if($db->getPdo()->getAttribute(\PDO::ATTR_PERSISTENT)){throw new \LogicException('Hosting execution requires a non-persistent database session.');}
            if((int)$db->getPdo()->query('SELECT @@autocommit')->fetchColumn()!==1){throw new \LogicException('Hosting execution requires autocommit.');}
            $database=(string)$db->getPdo()->query('SELECT DATABASE()')->fetchColumn();
            if($database===''){throw new \LogicException('Hosting execution database is not selected.');}
            $name='coleza:hosting:'.substr(hash('sha256',$database.':'.$serviceId),0,48);
            $key=spl_object_id($db->getPdo()).':'.$name;
        }elseif($driver==='sqlite'){
            foreach($db->select('PRAGMA database_list')as$row){if($row['name']==='main'){$database=$row['file'];break;}}
            if($database!==''){
                $database=realpath($database)?:throw new RuntimeException('SQLite hosting database path is unavailable.');
                $path=$database.'.hosting-'.$serviceId.'.lock';
            }
            $key=($path??'memory:'.spl_object_id($db->getPdo())).':'.$serviceId;
            if(DIRECTORY_SEPARATOR==='\\'){$key=strtolower($key);}
        }else{throw new \LogicException('Unsupported hosting execution database.');}
        if(isset(self::$held[$key])){throw new HostingExecutionBusy();}
        $lease=new self($db,$key,$database);
        try{
            if($driver==='mysql'){
                if((int)($db->selectOne('SELECT GET_LOCK(?,0) AS acquired',[$name])['acquired']??0)!==1){throw new HostingExecutionBusy();}
                $lease->databaseLock=$name;
            }elseif($path!==null){
                if(is_link($path)){throw new RuntimeException('Hosting execution lock must be a regular local file.');}
                $lease->file=fopen($path,'c+b');
                if($lease->file===false){throw new RuntimeException('Cannot open hosting execution lock.');}
                if(!flock($lease->file,LOCK_EX|LOCK_NB)){throw new HostingExecutionBusy();}
            }
            self::$held[$key]=$lease;return $lease;
        }catch(\Throwable $error){$lease->close();throw $error;}
    }
    /** Fail closed if the session/selected database or live lock was lost before a remote/local mutation. */
    public static function assertHeld(Connection $db,int $serviceId):void
    {
        foreach(self::$held as $lease){
            if($lease->db->getPdo()!==$db->getPdo()||$lease->closed){continue;}
            if($lease->databaseLock!==null){
                $expected='coleza:hosting:'.substr(hash('sha256',$lease->database.':'.$serviceId),0,48);
                if($expected!==$lease->databaseLock){continue;}
                $row=$db->selectOne('SELECT DATABASE() AS db_name, IS_USED_LOCK(?) AS owner, CONNECTION_ID() AS current_id',[$expected]);
                if($row['db_name']!==$lease->database||$row['owner']===null||(string)$row['owner']!==(string)$row['current_id']){throw new RuntimeException('Hosting execution session ownership was lost.');}
                return;
            }
            $suffix=':'.$serviceId;if(!str_ends_with($lease->key,$suffix)){continue;}
            $current='';foreach($db->select('PRAGMA database_list')as$row){if($row['name']==='main'){$current=$row['file'];break;}}
            if($current!==''&&(realpath($current)?:'')!==$lease->database){throw new RuntimeException('Hosting execution database changed.');}
            if($lease->database!==''&&!is_resource($lease->file)){throw new RuntimeException('Hosting execution lock was closed.');}
            return;
        }
        throw new RuntimeException('A live hosting execution lease is required.');
    }
    public function close():void
    {
        if($this->closed){return;}$this->closed=true;
        if((self::$held[$this->key]??null)===$this){unset(self::$held[$this->key]);}
        try{
            if($this->databaseLock!==null){$name=$this->databaseLock;$this->databaseLock=null;$this->db->selectOne('SELECT RELEASE_LOCK(?) AS released',[$name]);}
        }finally{if(is_resource($this->file)){flock($this->file,LOCK_UN);fclose($this->file);}$this->file=null;}
    }
}
