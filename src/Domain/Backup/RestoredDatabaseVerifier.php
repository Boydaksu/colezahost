<?php
declare(strict_types=1);
namespace Coleza\Domain\Backup;

use Coleza\Foundation\Database\Connection;

final class RestoredDatabaseVerifier
{
    public function verify(Connection $db,array $inventory,bool $preserveAdditionalTables=false): void
    {
        if(($inventory['format']??null)!==2||($inventory['driver']??null)!==$db->getDriverName()||!is_array($inventory['tables']??null)){throw new \RuntimeException('Backup database inventory is invalid or has a different driver.');}
        $tables=(new LogicalDatabaseDump())->tables($db);$expected=array_keys($inventory['tables']);sort($expected);sort($tables);
        if(($preserveAdditionalTables&&array_diff($expected,$tables)!==[])||(!$preserveAdditionalTables&&$tables!==$expected)){throw new \RuntimeException('Restored database table inventory mismatch.');}
        foreach($inventory['tables']as$table=>$description){
            if(!is_int($description['rows']??null)||$description['rows']<0||(int)$db->getPdo()->query('SELECT COUNT(*) FROM '.LogicalDatabaseDump::identifier($table))->fetchColumn()!==$description['rows']){throw new \RuntimeException('Restored database row count mismatch.');}
        }
        if($db->getDriverName()==='sqlite'){
            if($db->select('PRAGMA foreign_key_check')!==[]||(string)$db->getPdo()->query('PRAGMA integrity_check')->fetchColumn()!=='ok'){throw new \RuntimeException('Restored SQLite integrity check failed.');}return;
        }
        $groups=[];
        foreach($db->select('SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME,CONSTRAINT_NAME,REFERENCED_TABLE_SCHEMA FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION')as$row){$groups[$row['TABLE_NAME'].'/'.$row['CONSTRAINT_NAME']][]=$row;}
        $schema=(string)$db->getPdo()->query('SELECT DATABASE()')->fetchColumn();
        foreach($groups as$rows){$conditions=[];$nonnull=[];$first=$rows[0];
            foreach($rows as$row){if($row['REFERENCED_TABLE_SCHEMA']!==$schema){throw new \RuntimeException('Cross-database restore foreign keys are unsupported.');}$child='c.'.LogicalDatabaseDump::identifier($row['COLUMN_NAME']);$conditions[]='p.'.LogicalDatabaseDump::identifier($row['REFERENCED_COLUMN_NAME']).'='.$child;$nonnull[]=$child.' IS NOT NULL';}
            $sql='SELECT 1 FROM '.LogicalDatabaseDump::identifier($first['TABLE_NAME']).' c WHERE '.implode(' AND ',$nonnull).' AND NOT EXISTS (SELECT 1 FROM '.LogicalDatabaseDump::identifier($first['REFERENCED_TABLE_NAME']).' p WHERE '.implode(' AND ',$conditions).') LIMIT 1';
            if($db->selectOne($sql)!==null){throw new \RuntimeException('Restored database contains an orphan foreign key.');}
        }
    }
}
