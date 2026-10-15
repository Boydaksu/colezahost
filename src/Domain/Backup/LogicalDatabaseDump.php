<?php
declare(strict_types=1);
namespace Coleza\Domain\Backup;

use Coleza\Foundation\Database\Connection;
use PDO;
use RuntimeException;

/** Portable logical dump of the supported application table schema, from one consistent snapshot. */
final class LogicalDatabaseDump
{
    public static function identifier(string $name): string { return '`'.str_replace('`','``',$name).'`'; }

    /** @return array{format:int,driver:string,sql_mode:string,tables:array<string,array{rows:int}>} */
    public function export(Connection $db, string $destination,?callable $progress=null): array
    {
        $pdo=$db->getPdo();$driver=$db->getDriverName();
        if($pdo->inTransaction()||$db->inTransaction()){throw new RuntimeException('Database backup requires its own consistent snapshot; caller transaction retained.');}
        if(!in_array($driver,['mysql','sqlite'],true)){throw new RuntimeException('Unsupported backup database driver.');}
        $mysql=$driver==='mysql';$mode=$mysql?(string)$pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn():'';
        $buffered=$mysql?$pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY):null;
        $charset=$mysql?$db->selectOne('SELECT @@character_set_client AS client,@@character_set_connection AS connection,@@character_set_results AS results,@@collation_connection AS collation,@@sql_quote_show_create AS quote_create,@@time_zone AS tz'):null;
        $exportModes=array_values(array_filter(explode(',',$mode),static fn(string $value):bool=>!in_array($value,['NO_BACKSLASH_ESCAPES','ANSI_QUOTES',''],true)));
        if($mysql&&!in_array('NO_AUTO_VALUE_ON_ZERO',$exportModes,true)){$exportModes[]='NO_AUTO_VALUE_ON_ZERO';}$exportMode=implode(',',$exportModes);
        $output=fopen($destination,'xb');if($output===false){throw new RuntimeException('Cannot create private database dump.');}chmod($destination,0600);
        $inventory=['format'=>2,'driver'=>$driver,'sql_mode'=>$exportMode,'time_zone'=>$mysql?'+00:00':'','tables'=>[]];
        try{
            if($mysql){$pdo->exec("SET SESSION time_zone='+00:00'");$pdo->exec('SET NAMES utf8mb4');$statement=$pdo->prepare('SET SESSION sql_mode=?');$statement->execute([$exportMode]);$pdo->exec('SET SESSION sql_quote_show_create=1');$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');}
            else{$pdo->beginTransaction();}
            $tables=$this->tables($db);$this->assertSupportedObjects($db);
            $schemas=[];$columns=[];
            foreach($tables as$table){
                $q=self::identifier($table);
                // Acquire a metadata lock before reading schema; hold it to snapshot completion.
                $statement=$pdo->query('SELECT * FROM '.$q.' LIMIT 0');$statement->closeCursor();
                if($mysql){$row=$db->selectOne('SHOW CREATE TABLE '.$q);$schemas[$table]=$row['Create Table'];$cols=$db->select('SHOW FULL COLUMNS FROM '.$q);$columns[$table]=array_column(array_filter($cols,static fn(array $c):bool=>!preg_match('/(?:VIRTUAL|STORED|PERSISTENT) GENERATED/i',$c['Extra'])),'Field');}
                else{$row=$db->selectOne("SELECT sql FROM sqlite_master WHERE type='table' AND name=?",[$table]);$schemas[$table]=$row['sql'];$cols=$db->select('PRAGMA table_xinfo('.$q.')');$columns[$table]=array_column(array_filter($cols,static fn(array $c):bool=>(int)$c['hidden']===0),'name');}
                $this->put($output,'DROP TABLE IF EXISTS '.$q.";\n".$schemas[$table].";\n");
            }
            foreach($tables as$table){
                $cols=$columns[$table];$quoted=array_map(self::identifier(...),$cols);$count=count($cols);
                $select=implode(',',$quoted);
                if(!$mysql){$select.=','.implode(',',array_map(static fn(string $q):string=>'typeof('.$q.')',$quoted));}
                if($mysql){$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);}
                $statement=$pdo->query('SELECT '.$select.' FROM '.self::identifier($table));$rows=0;
                try{while(($row=$statement->fetch(PDO::FETCH_NUM))!==false){
                    $values=[];
                    for($i=0;$i<$count;$i++){
                        $value=$row[$i];
                        if($value===null){$values[]='NULL';}
                        elseif(is_int($value)){$values[]=(string)$value;}
                        elseif(is_float($value)){if(!is_finite($value)){throw new RuntimeException('Non-finite database value cannot be backed up.');}$values[]=json_encode($value,JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);}
                        else{if(strlen((string)$value)>16*1024*1024){throw new RuntimeException('A database value exceeds the supported 16 MiB backup limit.');}$hex="X'".bin2hex((string)$value)."'";$values[]=(!$mysql&&$row[$i+$count]==='text')?'CAST('.$hex.' AS TEXT)':$hex;}
                    }
                    $this->put($output,'INSERT INTO '.self::identifier($table).' ('.implode(',',$quoted).') VALUES ('.implode(',',$values).");\n");$rows++;
                }}finally{$statement->closeCursor();if($mysql){$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$buffered);}}
                $inventory['tables'][$table]=['rows'=>$rows];
                if($progress!==null){$progress($table,$rows);}
            }
            if(!$mysql){
                foreach($db->select("SELECT sql FROM sqlite_master WHERE type='index' AND sql IS NOT NULL ORDER BY name")as$row){$this->put($output,$row['sql'].";\n");}
                if($db->selectOne("SELECT 1 FROM sqlite_master WHERE name='sqlite_sequence'")!==null){
                    $this->put($output,"DELETE FROM sqlite_sequence;\n");
                    foreach($db->select('SELECT name,seq FROM sqlite_sequence')as$row){$this->put($output,"INSERT INTO sqlite_sequence(name,seq) VALUES (CAST(X'".bin2hex($row['name'])."' AS TEXT),".(int)$row['seq'].");\n");}
                }
            }
            if($tables!==$this->tables($db)){throw new RuntimeException('Database schema changed during backup; retry in maintenance.');}
            foreach($tables as$table){$current=$mysql?$db->selectOne('SHOW CREATE TABLE '.self::identifier($table))['Create Table']:$db->selectOne("SELECT sql FROM sqlite_master WHERE type='table' AND name=?",[$table])['sql'];
                // AUTO_INCREMENT may advance concurrently; structure, not the allocation counter, is compared.
                if(preg_replace('/ AUTO_INCREMENT=\d+/','',$current)!==preg_replace('/ AUTO_INCREMENT=\d+/','',$schemas[$table])){throw new RuntimeException('Database table changed during backup.');}}
            $this->assertSupportedObjects($db);
            if(!fflush($output)||!fsync($output)){throw new RuntimeException('Cannot persist database dump.');}
            $pdo->commit();return $inventory;
        }finally{if($pdo->inTransaction()){$pdo->rollBack();}if($mysql){$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$buffered);$statement=$pdo->prepare('SET SESSION sql_mode=?');$statement->execute([$mode]);$statement=$pdo->prepare('SET character_set_client=?,character_set_connection=?,character_set_results=?,collation_connection=?');$statement->execute([$charset['client'],$charset['connection'],$charset['results'],$charset['collation']]);$pdo->exec('SET SESSION sql_quote_show_create='.(int)$charset['quote_create']);$statement=$pdo->prepare('SET SESSION time_zone=?');$statement->execute([$charset['tz']]);}fclose($output);}
    }

    /** @return list<string> */
    public function tables(Connection $db): array
    {
        if($db->getDriverName()==='sqlite'){return array_column($db->select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"),'name');}
        $rows=$db->select('SELECT TABLE_NAME,ENGINE,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME');$names=[];
        foreach($rows as$row){if($row['TABLE_TYPE']!=='BASE TABLE'||strcasecmp((string)$row['ENGINE'],'InnoDB')!==0){throw new RuntimeException('Consistent backup requires InnoDB base tables; unsupported database object found.');}$names[]=$row['TABLE_NAME'];}return $names;
    }
    private function assertSupportedObjects(Connection $db): void
    {
        if($db->getDriverName()==='sqlite'){$objects=$db->select("SELECT name FROM sqlite_master WHERE type IN ('view','trigger')");}
        else{$objects=array_merge($db->select('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()'),$db->select('SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()'),$db->select('SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()'));}
        if($objects!==[]){throw new RuntimeException('Database views, triggers, routines and events require a dedicated backup exporter; no partial dump was published.');}
    }
    /** @param resource $output */
    private function put(mixed $output,string $bytes): void { if(strlen($bytes)>64*1024*1024){throw new RuntimeException('Database dump statement exceeds the supported 64 MiB limit.');}if(fwrite($output,$bytes)!==strlen($bytes)){throw new RuntimeException('Database dump write failed.');} }
}
