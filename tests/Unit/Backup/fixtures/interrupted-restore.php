<?php
declare(strict_types=1);
require dirname(__DIR__,4).'/vendor/autoload.php';
$job=json_decode((string)stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR);
$target=new \Coleza\Foundation\Database\Connection(new PDO('sqlite:'.$job['database']));
$restore=new \Coleza\Domain\Backup\RestoreWizardService($job['stage'],checkpoint:static function(string $phase,int $count):void{if($phase==='import_statement'&&$count===2){exit(73);}});
$restore->executeRestore($job['id'],new \Coleza\Domain\Backup\Storage\LocalBackupStorageAdapter($job['storage']),$target,$job['target']);
exit(74);
