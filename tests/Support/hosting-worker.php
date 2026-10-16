<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use Coleza\Domain\Providers\Cpanel\{CpanelProvider,CpanelCurlTransport};
use Coleza\Domain\Providers\Registry\ProviderRegistry;
use Coleza\Domain\Commerce\Services\ServiceService;
use Coleza\Domain\Servers\Services\ServerService;
use Coleza\Domain\Servers\Capacity\Services\CapacityReservationService;
use Coleza\Domain\Servers\Placement\PlacementEngine;
use Coleza\Domain\Provisioning\Services\ProvisioningOperationService;
use Coleza\Domain\Provisioning\Workflows\{HostingProvisioningWorkflow,HostingLifecycleWorkflow};
use Coleza\Application\Provisioning\HostingApplicationService;
$config=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);
file_put_contents($config['root'].'/'.$config['name'].'.ready','ready');
if($config['barrier']){
    $deadline=microtime(true)+15;while(!is_file($config['root'].'/go')){if(microtime(true)>$deadline){throw new RuntimeException('Worker start barrier expired.');}usleep(10000);}
}
try{
    if($config['driver']==='mysql'){
        if(!preg_match('/^colezahost_d02_test_[a-f0-9]{16}$/D',$config['database'])){throw new RuntimeException('Isolated test database required.');}
        $pdo=new PDO(getenv('COLEZA_TEST_MARIADB_DSN').';dbname='.$config['database'],getenv('COLEZA_TEST_MARIADB_USER'),getenv('COLEZA_TEST_MARIADB_PASSWORD'));
    }else{
        $file=realpath($config['database']);if($file===false||basename($file)!=='hosting.sqlite'||!preg_match('/^coleza_host_[a-f0-9]{24}$/D',basename(dirname($file)))){throw new RuntimeException('Isolated SQLite test database required.');}
        $pdo=new PDO('sqlite:'.$file);
    }
    $db=new Coleza\Foundation\Database\Connection($pdo);$key=Coleza\Tests\Support\SecretFixtureKey::encryptor();
    $services=new ServiceService($db);$servers=new ServerService($db,$key);$capacity=new CapacityReservationService($db,$servers);$ops=new ProvisioningOperationService($db,$key);
    $providers=new ProviderRegistry([new CpanelProvider(new CpanelCurlTransport(caBundle:$config['ca']))]);
    $app=new HostingApplicationService(new HostingProvisioningWorkflow($services,$servers,new PlacementEngine($servers),$capacity,$providers,$ops),new HostingLifecycleWorkflow($services,$servers,$capacity,$ops,$providers),$ops,$services,$servers,$providers);
    $result=match($config['action']){'provision'=>$app->provision($config['service_id'],3),'reconcile'=>$app->reconcile($config['uuid'],4),default=>$app->{$config['action']}($config['service_id'],3,'Fixture process command')};
    if($result instanceof Coleza\Domain\Provisioning\Entities\ProvisioningOperation){$output=['success'=>$result->getStatus()==='completed','status'=>$result->getStatus(),'uuid'=>$result->getOperationUuid()];}
    elseif($result instanceof Coleza\Domain\Provisioning\Reconciliation\ReconciliationResult){$output=['success'=>$result->isExists(),'status'=>$result->isExists()?'completed':'unknown'];}
    else{$output=['success'=>$result->isSuccess(),'result'=>$result->toArray()];}
}catch(Throwable $error){$output=['success'=>false,'error'=>get_class($error),'message'=>$error->getMessage()];}
file_put_contents($config['root'].'/'.$config['name'].'.done',json_encode($output,JSON_THROW_ON_ERROR));
echo json_encode($output,JSON_THROW_ON_ERROR);
