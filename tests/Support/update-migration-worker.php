<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use Coleza\Domain\Backup\PreUpdateBackupService;
use Coleza\Domain\Updater\{ModuleCompatibilityChecker,PackageSignatureVerifier,StagedUpdateService};
use Coleza\Foundation\Database\Connection;
$config=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);
$pdo=new PDO($config['dsn'],getenv('COLEZA_TEST_MARIADB_USER')?:null,getenv('COLEZA_TEST_MARIADB_PASSWORD')?:null);
if($config['database']!==null){
    if(!preg_match('/^colezahost_d02_test_[a-f0-9]{16}$/D',$config['database'])){throw new RuntimeException('Isolated database required.');}
    $pdo->exec('USE `'.$config['database'].'`');
}
$db=new Connection($pdo);$root=$config['root'];
$checkpoint=function(string $stage)use($root,$argv):void{
    if($stage==='package_frozen'&&$argv[2]==='hold'){
        file_put_contents($root.'/ready','ready');$deadline=microtime(true)+20;
        while(!file_exists($root.'/release')&&microtime(true)<$deadline){usleep(10000);}
        if(!file_exists($root.'/release')){throw new RuntimeException('Worker barrier timed out.');}
    }
};
try{
    $updater=new StagedUpdateService(new PackageSignatureVerifier($config['public']),new ModuleCompatibilityChecker($db),'1.0.0',$db,new PreUpdateBackupService($root.'/backup',$db),$checkpoint);
    $result=$updater->applyValidatedUpdate($root.'/package',$root.'/app');echo $result['migrations_run'];
}catch(Throwable $error){echo $error->getMessage();exit(1);}
