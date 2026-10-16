<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
use Coleza\Application\Provisioning\HostingApplicationService;
use Coleza\Domain\Commerce\Services\{ServiceService,ProviderServiceEntitlement};
use Coleza\Domain\Providers\Cpanel\CpanelProvider;
use Coleza\Domain\Providers\Registry\ProviderRegistry;
use Coleza\Domain\Provisioning\Services\ProvisioningOperationService;
use Coleza\Domain\Provisioning\Workflows\{HostingProvisioningWorkflow,HostingLifecycleWorkflow};
use Coleza\Domain\Servers\Services\ServerService;
use Coleza\Domain\Servers\Capacity\Services\CapacityReservationService;
use Coleza\Domain\Servers\Placement\PlacementEngine;
use Coleza\Domain\Fraud\Risk\{RiskContext,RiskScoreService};
use Coleza\Domain\Fraud\Review\RiskReviewService;
use PHPUnit\Framework\Attributes\DataProvider;
trait HostingCommandFixture
{
    private function commandFixture(array $serverOptions=[]):array
    {
        $db=$this->db;$sessions=new \Coleza\Domain\Identity\Session\DatabaseSessionHandler($db);
        (new \Coleza\Domain\Identity\Auth\AuthService($db,new \Coleza\Domain\Identity\Security\PasswordHasher(),$sessions))->ensureTables();
        foreach([2,3,4]as$id){$db->insert('users',['id'=>$id,'name'=>'Fixture user','email'=>'owner'.$id.'@example.test','password_hash'=>'unused','is_active'=>1]);}
        $rbac=new \Coleza\Domain\Identity\Rbac\RbacService($db);$rbac->ensureTables();$role=$rbac->findOrCreateRole('hosting_operator');foreach(['services.provision','services.suspend','services.unsuspend','services.terminate','fraud.review']as$permission){$rbac->grantPermission($role,$permission);}$rbac->assignRole(3,'hosting_operator');$rbac->assignRole(4,'hosting_operator');
        (new \Coleza\Domain\Identity\Audit\AuditLogger($db))->ensureTable();
        $services=new ServiceService($db);$services->ensureTables();$servers=new ServerService($db,SecretFixtureKey::encryptor());$servers->ensureTables();$server=$servers->createServer(array_replace(['name'=>'Fixture','hostname'=>'fixture.test','ip_address'=>'127.0.0.2','provider_slug'=>'cpanel','max_accounts'=>100,'auth_secret'=>'fixture-only-token'],$serverOptions));
        $capacity=new CapacityReservationService($db,$servers);$capacity->ensureTables();$ops=new ProvisioningOperationService($db,SecretFixtureKey::encryptor());$ops->ensureTables();
        $catalog=new \Coleza\Domain\Catalog\Services\CatalogService($db);$catalog->ensureTables();$group=$catalog->createProductGroup(['name'=>'Hosting','slug'=>'hosting']);
        $product=$catalog->createProduct(['name'=>'Fixture hosting','slug'=>'fixture-hosting','group_id'=>$group->getId(),'type'=>'hosting','metadata'=>['hosting'=>['provider'=>'cpanel','package'=>'starter','disk_mb'=>10,'bandwidth_mb'=>100,'server_id'=>$server->getId()]]]);
        $pricing=new \Coleza\Domain\Pricing\Services\PricingService($db,null,$catalog);$pricing->ensureTables();$pricing->setPricePoint(['target_id'=>$product->getId(),'currency_code'=>'TRY','cycle'=>'monthly','price_minor'=>1000]);
        $tax=new \Coleza\Domain\Tax\Services\TaxService($db);$tax->ensureTables();$class=$tax->createTaxClass(['code'=>'standard','name'=>'Standard','is_default'=>true]);$zone=$tax->createTaxZone(['code'=>'TR','name'=>'Turkey','country_codes'=>['TR']]);$tax->createTaxRate(['tax_class_id'=>$class->getId(),'tax_zone_id'=>$zone->getId(),'name'=>'VAT','rate_percent'=>20]);
        $orders=new \Coleza\Domain\Commerce\Orders\OrderService($db,$pricing,$tax,$catalog);$orders->ensureTables();$invoices=new \Coleza\Domain\Commerce\Invoices\InvoiceService($db);$invoices->ensureTables();$payments=new \Coleza\Domain\Commerce\Payments\PaymentService($db,$invoices,$orders);$payments->ensureTables();
        $order=$orders->createOrder(['user_id'=>2,'currency_code'=>'TRY'],[['product_id'=>$product->getId(),'cycle'=>'monthly','metadata'=>['domain'=>'client.test']]]);$invoice=$invoices->createInvoiceFromOrder($order);
        $payment=$payments->recordPayment(['user_id'=>2,'invoice_id'=>$invoice->getId(),'currency_code'=>'TRY','amount_minor'=>$invoice->getTotalMinor(),'payment_method'=>'bank_transfer']);$payments->approveManualPayment($payment->getId());$service=$services->createServicesFromOrder($orders->findOrderById($order->getId()))[0];
        $risk=new RiskScoreService($db);$risk->ensureTables(true);$reviews=new RiskReviewService($db);$reviews->ensureTables(true);
        $transport=new HostingWorkflowTransport();$transport->memory->stageResponse('listpkgs',200,['metadata'=>['result'=>1],'data'=>['pkg'=>[['name'=>'starter','QUOTA'=>'100','BWLIMIT'=>'1000']]]]);
        foreach(['createacct','suspendacct','unsuspendacct','removeacct']as$endpoint){$transport->memory->stageResponse($endpoint,200,['metadata'=>['result'=>1],'data'=>['ip'=>'127.0.0.2']]);}
        $this->commandSummary($transport);$transport->memory->stageResponse('listaccts',200,['metadata'=>['result'=>1],'data'=>['acct'=>[['user'=>'client'.$service->getId()]]]]);
        $transport->after=function($url)use($transport):void{
            if(str_contains($url,'/suspendacct')){$this->commandSummary($transport,1);}
            if(str_contains($url,'/unsuspendacct')){$this->commandSummary($transport,0);}
            if(str_contains($url,'/removeacct')){$transport->memory->stageResponse('listaccts',200,['metadata'=>['result'=>1],'data'=>['acct'=>[]]]);}
        };
        $registry=new ProviderRegistry([new CpanelProvider($transport)]);$workflow=new HostingProvisioningWorkflow($services,$servers,new PlacementEngine($servers),$capacity,$registry,$ops);$lifecycle=new HostingLifecycleWorkflow($services,$servers,$capacity,$ops,$registry);
        $app=new HostingApplicationService($workflow,$lifecycle,$ops,$services,$servers,$registry);
        $f=compact('app','services','servers','server','capacity','ops','transport','order','invoice','service','risk','reviews','product','registry','payments','payment');$this->evaluateCommandRisk($f);return $f;
    }
    private function commandSummary(HostingWorkflowTransport $t,int $suspended=0):void
    {
        // Generated username for client.test + first service id is client1.
        $t->memory->stageResponse('accountsummary',200,['metadata'=>['result'=>1],'data'=>['acct'=>[['user'=>'client1','domain'=>'client.test','plan'=>'starter','ip'=>'127.0.0.2','suspended'=>$suspended]]]]);
    }
    private function evaluateCommandRisk(array $f,string $decision='accept')
    {
        return $f['risk']->evaluate(new RiskContext('order',$f['order']->getId(),2,organizationId:($this->db->selectOne('SELECT organization_id FROM orders WHERE id=?',[$f['order']->getId()])['organization_id']===null?null:(int)$this->db->selectOne('SELECT organization_id FROM orders WHERE id=?',[$f['order']->getId()])['organization_id']),email:'owner2@example.test',orderAmount:$f['order']->getTotalMinor()/100,currency:'TRY',isNewCustomer:false,
            customAttributes:['order_total_minor'=>$f['order']->getTotalMinor(),'order_fingerprint'=>(new ProviderServiceEntitlement($this->db))->orderFingerprint($f['order']->getId())]),
            reviewThreshold:$decision==='accept'?30:0,rejectThreshold:$decision==='reject'?0:100);
    }
    private function provisionCommand(array $f):void{self::assertTrue($f['app']->provision($f['service']->getId(),3)->isSuccess());}
    private function commandPosts(array $f):array{return array_values(array_filter($f['transport']->memory->getRecordedRequests(),fn($r)=>$r['method']==='POST'));}
}
