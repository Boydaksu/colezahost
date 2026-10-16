<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
$config=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);$root=$config['root'];
$pdo=new PDO($config['dsn'],getenv('COLEZA_TEST_MARIADB_USER')?:null,getenv('COLEZA_TEST_MARIADB_PASSWORD')?:null);
if($config['database']!==null){if(!preg_match('/^colezahost_d02_test_[a-f0-9]{16}$/D',$config['database'])){throw new RuntimeException('Isolated test database required.');}$pdo->exec('USE `'.$config['database'].'`');}
$db=new \Coleza\Foundation\Database\Connection($pdo);
$checkpoint=function(string $stage)use($argv,$root):void{
    if($argv[2]==='kill:'.$stage){exit(76);}
    if($argv[2]==='hold'&&$stage==='extension_backup_verified'){file_put_contents($root.'/ready','ready');$deadline=microtime(true)+20;while(!file_exists($root.'/release')&&microtime(true)<$deadline){usleep(10000);}if(!file_exists($root.'/release')){throw new RuntimeException('Extension worker barrier timed out.');}}
};
try{
    $installer=new \Coleza\Domain\Module\ExtensionInstaller($db,new \Coleza\Domain\Updater\PackageSignatureVerifier($config['public']),$root.'/modules',$root.'/public',new \Coleza\Domain\Backup\PreUpdateBackupService($root.'/backup',$db),adapterHealth:static fn(string $id,$context):bool=>$context->count('records')===0,checkpoint:$checkpoint);
    if($argv[2]==='recover'){$result=$installer->recover();echo $result['success']?'recovered':'failed';}
    else{$preview=$installer->preview($root.'/package');$installer->install($root.'/package',$preview['digest'],$preview['permissions']);echo 'installed';}
}catch(Throwable $error){echo $error->getMessage();exit(1);}
