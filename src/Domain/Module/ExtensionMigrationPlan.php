<?php
declare(strict_types=1);
namespace Coleza\Domain\Module;
use Coleza\Foundation\Database\Connection;
use RuntimeException;

/** Closed declarative grammar: no uploaded PHP execution, raw SQL or Core table identifiers. */
final class ExtensionMigrationPlan
{
    public static function prefix(string $id): string {return 'ext_'.substr(hash('sha256',$id),0,24).'_';}
    public static function local(string $name): string
    {
        if(!preg_match('/^[a-z][a-z0-9_]{0,29}$/D',$name)||$name==='migration_ledger'){throw new RuntimeException('Invalid or reserved extension table/column name.');}return $name;
    }
    public static function compile(ExtensionPackage $package,Connection $db): array
    {
        $plan=[];$tables=[];$prefix=self::prefix($package->manifest->id);$operations=0;
        foreach($package->migrationFiles as $file){
            $path=$package->snapshot->directory().'/files/'.$file;if(filesize($path)>65536){throw new RuntimeException('Extension migration descriptor exceeds limit.');}
            $data=ExtensionPackage::decode(file_get_contents($path));
            if(array_keys($data)!==['operations']||!is_array($data['operations'])||!array_is_list($data['operations'])||$data['operations']===[]||count($data['operations'])>100){throw new RuntimeException('Invalid extension migration operation list.');}
            $sql=[];
            foreach($data['operations'] as $op){
                if(++$operations>100){throw new RuntimeException('Extension migration operation budget exceeded.');}
                if(!is_array($op)||array_diff(array_keys($op),['op','table','columns'])!==[]||($op['op']??null)!=='create_table'||!is_string($op['table']??null)||!is_array($op['columns']??null)||$op['columns']===[]||count($op['columns'])>64){throw new RuntimeException('Only declarative namespaced create_table migrations are supported.');}
                $table=$prefix.self::local($op['table']);if(isset($tables[$table])){throw new RuntimeException('Duplicate extension table declaration.');}$tables[$table]=[];$columns=[];$primary=0;
                foreach($op['columns'] as $name=>$column){
                    if(!is_string($name)||!is_array($column)||array_diff(array_keys($column),['type','length','nullable','primary'])!==[]){throw new RuntimeException('Invalid extension column descriptor.');}self::local($name);
                    foreach(['nullable','primary'] as $flag){if(array_key_exists($flag,$column)&&!is_bool($column[$flag])){throw new RuntimeException('Invalid extension column flag.');}}
                    $type=match($column['type']??null){'integer'=>'INT','bigint'=>'BIGINT','text'=>'TEXT','binary'=>$db->getDriverName()==='mysql'?'LONGBLOB':'BLOB','string'=>'VARCHAR('.self::length($column['length']??null).')',default=>throw new RuntimeException('Unknown extension column type.')};
                    if(isset($column['length'])&&($column['type']??null)!=='string'){throw new RuntimeException('Unexpected extension column length.');}
                    if(($column['primary']??false)&&++$primary>1){throw new RuntimeException('Only one primary column is supported.');}
                    $columns[]='`'.$name.'` '.$type.(($column['nullable']??false)?' NULL':' NOT NULL').(($column['primary']??false)?' PRIMARY KEY':'');$tables[$table][]=$name;
                }
                $sql[]='CREATE TABLE `'.$table.'` ('.implode(',',$columns).')'.($db->getDriverName()==='mysql'?' ENGINE=InnoDB':'');
            }
            $plan[]=['file'=>$file,'sha256'=>$package->snapshot->manifest->getFileChecksums()[$file],'sql'=>$sql];
        }
        return ['migrations'=>$plan,'tables'=>$tables];
    }
    private static function length(mixed $length): int {if(!is_int($length)||$length<1||$length>255){throw new RuntimeException('Extension string length must be 1..255.');}return $length;}
}
