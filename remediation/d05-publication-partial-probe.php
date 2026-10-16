<?php
declare(strict_types=1);
// Additional acceptance evidence: real throwing up(), followed by explicit rollback.
require dirname(__DIR__).'/vendor/autoload.php';
use Coleza\Foundation\Database\Migrator;
use Coleza\Domain\Backup\LogicalDatabaseDump;
$results=[];
foreach([Coleza\Tests\Unit\Updater\UpdatePublicationTest::class,Coleza\Tests\MariaDb\UpdatePublicationTest::class] as $class){
    $case=new $class('testFailureBeforeSwapRestoresDatabaseAndExactOldFiles');$prepared=false;
    $invoke=static fn(string $method,mixed ...$args):mixed=>(new ReflectionMethod($case,$method))->invoke($case,...$args);
    try{
        $invoke('setUp');$prepared=true;$invoke('publicationPackage',true);
        $invoke('publicationReplaceFile','db/new_table.php','<?php return new class implements \\Coleza\\Foundation\\Database\\MigrationInterface {public function up(\\Coleza\\Foundation\\Database\\Connection $db): void {$db->statement("CREATE TABLE new_records (id INT)");$db->statement("UPDATE old_records SET value = ?",["changed"]);throw new RuntimeException("deliberate partial DDL failure");}public function down(\\Coleza\\Foundation\\Database\\Connection $db): void {}};');
        $caught=false;try{$invoke('publicationApply');}catch(RuntimeException $error){if(!str_contains($error->getMessage(),'deliberate partial DDL failure')){throw $error;}$caught=true;}
        if(!$caught){throw new RuntimeException('The throwing migration was not executed.');}
        $db=(new ReflectionProperty($case,'db'))->getValue($case);$driver=$db->getDriverName();
        $partial=in_array('new_records',(new LogicalDatabaseDump())->tables($db),true);
        if($partial!==($driver==='mysql')||(new Migrator($db))->getRanMigrations()!==[]){throw new RuntimeException('Partial DDL/ledger evidence disagrees with driver contract.');}
        $root=(new ReflectionProperty($case,'publicationRoot'))->getValue($case);$updater=$invoke('publicationUpdater');
        $result=$updater->recoverFailedUpdate($root.'/app');if(!$result['success']){throw new RuntimeException('Explicit partial-DDL rollback failed.');}
        $invoke('assertOldPublicationRestored');
        $results[]=['driver'=>$driver,'throwing_up_executed'=>true,'partial_table_before_recovery'=>$partial,'failed_migration_ledger_rows'=>0,'rollback_success'=>true,'old_files_and_rows_verified'=>true];
    }finally{if($prepared){$invoke('tearDown');}}
}
echo json_encode($results,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
