<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;

use Coleza\Domain\Commerce\Orders\OrderService;
use Coleza\Domain\Commerce\Invoices\InvoiceService;
use Coleza\Domain\Commerce\Payments\PaymentService;
use Coleza\Domain\Commerce\Services\ServiceService;
use Coleza\Foundation\Exceptions\ValidationException;

trait CommerceFlowCases
{
    private function flowFixture(): array
    {
        $db=$this->db;
        $catalog=new \Coleza\Domain\Catalog\Services\CatalogService($db);$catalog->ensureTables();$group=$catalog->createProductGroup(['name'=>'Hosting','slug'=>'hosting']);$product=$catalog->createProduct(['name'=>'Manual hosting','slug'=>'manual-hosting','group_id'=>$group->getId()]);
        $pricing=new \Coleza\Domain\Pricing\Services\PricingService($db,null,$catalog);$pricing->ensureTables();$pricing->setPricePoint(['target_id'=>$product->getId(),'currency_code'=>'TRY','cycle'=>'monthly','price_minor'=>1000,'setup_fee_minor'=>200]);
        $tax=new \Coleza\Domain\Tax\Services\TaxService($db);$tax->ensureTables();$class=$tax->createTaxClass(['code'=>'standard','name'=>'Standard','is_default'=>true]);$zone=$tax->createTaxZone(['code'=>'TR','name'=>'Turkey','country_codes'=>['TR']]);$tax->createTaxRate(['tax_class_id'=>$class->getId(),'tax_zone_id'=>$zone->getId(),'name'=>'VAT','rate_percent'=>20]);
        $orders=new OrderService($db,$pricing,$tax,$catalog);$orders->ensureTables();$invoices=new InvoiceService($db);$invoices->ensureTables();$payments=new PaymentService($db,$invoices,$orders);$payments->ensureTables();$services=new ServiceService($db);$services->ensureTables();(new \Coleza\Domain\Commerce\Recurring\RenewalInvoiceService($db,$invoices,$tax))->ensureTables();
        return [$orders,$invoices,$payments,$services,$product->getId(),$pricing,$tax];
    }
    private function paidFlowOrder(array $fixture,int $quantity=1): array
    {
        [$orders,$invoices,$payments, ,$product]=$fixture;
        $order=$orders->createOrder(['user_id'=>2,'currency_code'=>'TRY'],[['product_id'=>$product,'quantity'=>$quantity,'cycle'=>'monthly']]);$invoice=$invoices->createInvoiceFromOrder($order);
        $payment=$payments->recordPayment(['user_id'=>2,'invoice_id'=>$invoice->getId(),'currency_code'=>'TRY','amount_minor'=>$invoice->getTotalMinor(),'payment_method'=>'bank_transfer']);$payments->approveManualPayment($payment->getId());
        return [$orders->findOrderById($order->getId()),$invoice,$payment];
    }
    public function testOrderAndInvoiceListsHaveConstantQueryBudget(): void
    {
        $fixture=$this->flowFixture();[$orders,$invoices]=$fixture;for($i=0;$i<100;$i++){$this->paidFlowOrder($fixture);}
        $this->db->enableQueryLog();$this->db->flushQueryLog();$rows=$orders->listOrdersForUser(2);self::assertCount(100,$rows);self::assertCount(1,$rows[0]->getItems());self::assertLessThanOrEqual(2,count($this->db->getQueryLog()));
        $this->db->flushQueryLog();$rows=$invoices->listInvoicesForUser(2);self::assertCount(100,$rows);self::assertCount(1,$rows[0]->getItems());self::assertLessThanOrEqual(2,count($this->db->getQueryLog()));
    }
    public function testPaymentListsHaveConstantQueryBudget(): void
    {
        $fixture=$this->flowFixture();[, ,$payments]=$fixture;for($i=0;$i<100;$i++){$this->paidFlowOrder($fixture);}
        $this->db->enableQueryLog();$this->db->flushQueryLog();$rows=$payments->listPaymentsForUser(2);self::assertCount(100,$rows);self::assertCount(1,$rows[0]->getAllocations());self::assertLessThanOrEqual(3,count($this->db->getQueryLog()));
    }
    public function testUnpaidOrderCannotCreateService(): void
    {
        [$orders, , ,$services,$product]=$this->flowFixture();$order=$orders->createOrder(['user_id'=>2],[['product_id'=>$product,'cycle'=>'monthly']]);$this->expectException(ValidationException::class);$services->createServicesFromOrder($order);
    }
    public function testPaidOrderServiceCreationIsIdempotent(): void
    {
        $fixture=$this->flowFixture();[, , ,$services]=$fixture;[$order]=$this->paidFlowOrder($fixture);$first=$services->createServicesFromOrder($order);$second=$services->createServicesFromOrder($order);self::assertSame($first[0]->getId(),$second[0]->getId());self::assertCount(1,$this->db->select('SELECT * FROM services'));
    }
    public function testEachPaidOrderUnitCreatesOneService(): void
    {
        $fixture=$this->flowFixture();[, , ,$services]=$fixture;[$order]=$this->paidFlowOrder($fixture,3);$rows=$services->createServicesFromOrder($order);self::assertCount(3,$rows);foreach($rows as$row){self::assertSame(1000,$row->getRecurringAmountMinor());}self::assertCount(3,$services->createServicesFromOrder($order));
    }
    public function testInvoicePaymentListIncludesPendingAndAllocatedPayments(): void
    {
        $fixture=$this->flowFixture();[, ,$payments]=$fixture;[, $invoice]=$this->paidFlowOrder($fixture);$pending=$payments->recordPayment(['user_id'=>2,'invoice_id'=>$invoice->getId(),'currency_code'=>'TRY','amount_minor'=>100,'payment_method'=>'bank_transfer']);
        self::assertCount(2,$payments->listPaymentsForInvoice($invoice->getId()));
    }
    private function flowActors(): void
    {
        $db=$this->db;$sessions=new \Coleza\Domain\Identity\Session\DatabaseSessionHandler($db);(new \Coleza\Domain\Identity\Auth\AuthService($db,new \Coleza\Domain\Identity\Security\PasswordHasher(),$sessions))->ensureTables();
        foreach([2,3]as$id){$db->insert('users',['id'=>$id,'name'=>'Flow user','email'=>'flow'.$id.'@example.test','password_hash'=>'unused','is_active'=>1]);}
        $rbac=new \Coleza\Domain\Identity\Rbac\RbacService($db);$rbac->ensureTables();$role=$rbac->findOrCreateRole('manual_finance');foreach(['payments.approve','services.manage']as$permission){$rbac->grantPermission($role,$permission);}$rbac->assignRole(3,'manual_finance');
        (new \Coleza\Domain\Identity\Audit\AuditLogger($db))->ensureTable();
    }
    public function testManualCommerceVerticalUsesCatalogPendingApprovalAndRealRenewal(): void
    {
        [$orders,$invoices,$payments,$services,$product,$pricing,$tax]=$this->flowFixture();$this->flowActors();
        $orderApp=new \Coleza\Application\Commerce\Orders\OrderApplicationService($orders);$invoiceApp=new \Coleza\Application\Commerce\Invoices\InvoiceApplicationService($invoices);$paymentApp=new \Coleza\Application\Commerce\Payments\PaymentApplicationService($payments,$invoices);$manual=new \Coleza\Application\Commerce\ManualCommerceApplicationService($this->db);
        $order=$orderApp->createOrder(new \Coleza\Application\Commerce\Orders\CreateOrderCommand(2,[['product_id'=>$product,'quantity'=>2,'billing_cycle'=>'monthly']]),2);
        self::assertSame(2880,$order->getTotalMinor());$invoice=$invoiceApp->createForOrder($order->getId(),2);
        $pending=$paymentApp->recordManualPayment(new \Coleza\Application\Commerce\Payments\RecordManualPaymentCommand(2,$invoice->getId(),2880),2);self::assertSame('pending',$pending->getStatus());self::assertSame('unpaid',$invoices->findInvoiceById($invoice->getId())->getStatus());
        try{$manual->approvePayment($pending->getId(),2);self::fail('Customer approved payment');}catch(\Coleza\Foundation\Exceptions\AuthorizationException){}
        $manual->approvePayment($pending->getId(),3);self::assertSame(2880,$invoices->findInvoiceById($invoice->getId())->getPaidAmountMinor());self::assertCount(1,$payments->findPaymentById($pending->getId())->getAllocations());
        $rows=$manual->fulfillOrder($order->getId(),3);self::assertCount(2,$rows);
        foreach($rows as$row){self::assertSame('pending',$row->getStatus());self::assertSame(1000,$row->getRecurringAmountMinor());$manual->activateService($row->getId(),3);}
        $again=$manual->fulfillOrder($order->getId(),3);self::assertSame(array_map(fn($s)=>$s->getId(),$rows),array_map(fn($s)=>$s->getId(),$again));
        $pricing->setPricePoint(['target_id'=>$product,'currency_code'=>'TRY','cycle'=>'monthly','price_minor'=>9999,'setup_fee_minor'=>888]);self::assertSame(2880,$invoices->findInvoiceById($invoice->getId())->getTotalMinor());
        $renewals=new \Coleza\Domain\Commerce\Recurring\RenewalInvoiceService($this->db,$invoices,$tax);$scheduler=new \Coleza\Domain\Commerce\Recurring\Scheduler\ServiceRenewalScheduler($services,$renewals);$due=$rows[0]->getNextDueDate();$policy=new \Coleza\Domain\Commerce\Recurring\Scheduler\RenewalPolicy(sendNotification:false);
        $report=$scheduler->run($policy,$due);self::assertSame(2,$report->getGeneratedCount());$renewal=$report->getResults()[0]->getInvoice();self::assertSame(1200,$renewal->getTotalMinor());self::assertSame(1000,$renewal->getSubtotalMinor());
        $payment=$paymentApp->recordManualPayment(new \Coleza\Application\Commerce\Payments\RecordManualPaymentCommand(2,$renewal->getId(),1200),2);$manual->approvePayment($payment->getId(),3);$manual->approvePayment($payment->getId(),3);
        $renewedId=$renewal->getItems()[0]->getServiceId();$current=$services->findServiceById($renewedId);self::assertSame(\Coleza\Domain\Commerce\Recurring\BillingPeriod::calculateNextDueDate($due,'monthly'),$current->getNextDueDate());self::assertSame('active',$current->getStatus());self::assertArrayHasKey('renewal_settlement',$invoices->findInvoiceById($renewal->getId())->getItems()[0]->getMetadata());
        self::assertSame(0,$scheduler->run($policy,$due)->getGeneratedCount());self::assertCount(2,$this->db->select('SELECT * FROM services'));self::assertCount(2,$this->db->select('SELECT * FROM renewal_records'));
    }
    public function testPersonalApplicationListsMeetPrimaryPageBudgetAt100Rows(): void
    {
        $fixture=$this->flowFixture();$this->flowActors();[$orders,$invoices,$payments]=$fixture;for($i=0;$i<100;$i++){$this->paidFlowOrder($fixture);}
        $applications=[new \Coleza\Application\Commerce\Orders\OrderApplicationService($orders),new \Coleza\Application\Commerce\Invoices\InvoiceApplicationService($invoices),new \Coleza\Application\Commerce\Payments\PaymentApplicationService($payments,$invoices)];
        $this->db->enableQueryLog();foreach(['listUserOrders','listUserInvoices','listUserPayments']as$i=>$method){$this->db->flushQueryLog();$rows=$applications[$i]->$method(2,2);self::assertCount(100,$rows);self::assertLessThanOrEqual(30,count($this->db->getQueryLog()),$method);}
    }
    public function testForgedPaidOrderStateWithoutPaidInvoiceCannotFulfill(): void
    {
        [$orders, , ,$services,$product]=$this->flowFixture();$order=$orders->createOrder(['user_id'=>2],[['product_id'=>$product]]);$orders->transitionOrderStatus($order->getId(),'active');
        $this->expectException(ValidationException::class);$services->createServicesFromOrder($order);
    }
    public function testFulfillmentChildFailureAndCallerRollbackDoNotLeavePartialUnits(): void
    {
        $fixture=$this->flowFixture();[, , ,$services]=$fixture;[$order]=$this->paidFlowOrder($fixture,3);
        $this->db->statement($this->db->getDriverName()==='mysql'?"CREATE TRIGGER reject_second_unit BEFORE INSERT ON services FOR EACH ROW BEGIN IF (SELECT COUNT(*) FROM services)>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='unit failure'; END IF; END":"CREATE TRIGGER reject_second_unit BEFORE INSERT ON services WHEN (SELECT COUNT(*) FROM services)>0 BEGIN SELECT RAISE(ABORT,'unit failure'); END");
        try{$services->createServicesFromOrder($order);self::fail('Child failure ignored');}catch(\PDOException){self::assertCount(0,$this->db->select('SELECT * FROM services'));}$this->db->statement('DROP TRIGGER reject_second_unit');
        foreach(['raw','managed']as$mode){$operation=function()use($services,$order):void{self::assertCount(3,$services->createServicesFromOrder($order));self::assertTrue($this->db->getPdo()->inTransaction());throw new \RuntimeException('Caller rollback');};
            if($mode==='raw'){$this->db->getPdo()->beginTransaction();try{$operation();}catch(\RuntimeException){}finally{$this->db->getPdo()->rollBack();}}else{try{$this->db->transaction($operation);}catch(\RuntimeException){}}self::assertCount(0,$this->db->select('SELECT * FROM services'));
        }
        self::assertCount(3,$services->createServicesFromOrder($order));
    }
    public function testManualApprovalAuditFailureRestoresPaymentInvoiceAndOrder(): void
    {
        [$orders,$invoices,$payments, ,$product]=$this->flowFixture();$this->flowActors();$order=$orders->createOrder(['user_id'=>2],[['product_id'=>$product]]);$invoice=$invoices->createInvoiceFromOrder($order);$payment=$payments->recordPayment(['user_id'=>2,'invoice_id'=>$invoice->getId(),'amount_minor'=>$invoice->getTotalMinor(),'currency_code'=>'TRY','payment_method'=>'bank_transfer']);
        $this->db->statement($this->db->getDriverName()==='mysql'?"CREATE TRIGGER reject_manual_audit BEFORE INSERT ON security_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='audit failure'":"CREATE TRIGGER reject_manual_audit BEFORE INSERT ON security_audit_logs BEGIN SELECT RAISE(ABORT,'audit failure'); END");
        try{(new \Coleza\Application\Commerce\ManualCommerceApplicationService($this->db))->approvePayment($payment->getId(),3);self::fail('Audit failure ignored');}catch(\PDOException){self::assertSame('pending',$payments->findPaymentById($payment->getId())->getStatus());self::assertSame('unpaid',$invoices->findInvoiceById($invoice->getId())->getStatus());self::assertSame('pending_payment',$orders->findOrderById($order->getId())->getStatus());self::assertCount(0,$this->db->select('SELECT * FROM payment_allocations'));}
    }
    public function testManualActivationCannotBypassProviderEvidence(): void
    {
        $fixture=$this->flowFixture();$this->flowActors();[, , ,$services]=$fixture;[$order]=$this->paidFlowOrder($fixture);$service=$services->createServicesFromOrder($order)[0];$services->updateService($service->getId(),['server_name'=>'Remote node']);
        $this->expectException(\Coleza\Domain\Commerce\Services\ServiceProviderLifecycleRequiredException::class);(new \Coleza\Application\Commerce\ManualCommerceApplicationService($this->db))->activateService($service->getId(),3);
    }
    public function testRenewalSchedulerSkips100ExistingPeriodsWithinSixQueries(): void
    {
        [, $invoices, ,$services,$product, ,$tax]=$this->flowFixture();$renewals=new \Coleza\Domain\Commerce\Recurring\RenewalInvoiceService($this->db,$invoices,$tax);
        for($i=0;$i<100;$i++){$service=$services->createService(['user_id'=>2,'product_id'=>$product,'status'=>'active','next_due_date'=>'2026-11-01','currency_code'=>'TRY','recurring_amount_minor'=>1000]);$renewals->generateRenewalInvoice(['service_id'=>$service->getId()]);}
        $scheduler=new \Coleza\Domain\Commerce\Recurring\Scheduler\ServiceRenewalScheduler($services,$renewals);$this->db->enableQueryLog();$this->db->flushQueryLog();$report=$scheduler->run(new \Coleza\Domain\Commerce\Recurring\Scheduler\RenewalPolicy(sendNotification:false),'2026-11-01');
        self::assertSame(100,$report->getSkippedCount());self::assertSame(0,$report->getGeneratedCount());self::assertLessThanOrEqual(6,count($this->db->getQueryLog()));
    }
    public function testOrganizationPaymentBindingAndMembershipRevocationRemainScoped(): void
    {
        [$orders,$invoices,$payments, ,$product]=$this->flowFixture();$this->flowActors();$organizations=new \Coleza\Domain\Identity\Organization\OrganizationService($this->db);$org=$organizations->createOrganization(2,'Flow company','flow-company');
        $orderApp=new \Coleza\Application\Commerce\Orders\OrderApplicationService($orders);$invoiceApp=new \Coleza\Application\Commerce\Invoices\InvoiceApplicationService($invoices);$paymentApp=new \Coleza\Application\Commerce\Payments\PaymentApplicationService($payments,$invoices);
        $order=$orderApp->createOrder(new \Coleza\Application\Commerce\Orders\CreateOrderCommand(2,[['product_id'=>$product]],organizationId:$org),2);$invoice=$invoiceApp->createForOrder($order->getId(),2);$payment=$paymentApp->recordManualPayment(new \Coleza\Application\Commerce\Payments\RecordManualPaymentCommand(2,$invoice->getId(),$invoice->getTotalMinor()),2);
        self::assertSame($org,$payment->getOrganizationId());(new \Coleza\Application\Commerce\ManualCommerceApplicationService($this->db))->approvePayment($payment->getId(),3);
        self::assertCount(1,$invoiceApp->listUserInvoices(2,2));$this->db->statement('DELETE FROM organization_members WHERE organization_id=? AND user_id=?',[$org,2]);self::assertSame([],$invoiceApp->listUserInvoices(2,2));self::assertSame([],$orderApp->listUserOrders(2,2));self::assertSame([],$paymentApp->listUserPayments(2,2));
    }
    public function testRenewalApprovalAuditFailureRestoresFinancialAndServiceAggregate(): void
    {
        $fixture=$this->flowFixture();$this->flowActors();[, $invoices,$payments,$services, , ,$tax]=$fixture;[$order]=$this->paidFlowOrder($fixture);$service=$services->createServicesFromOrder($order)[0];$services->activateService($service->getId());$renewal=(new \Coleza\Domain\Commerce\Recurring\RenewalInvoiceService($this->db,$invoices,$tax))->generateRenewalInvoice(['service_id'=>$service->getId()]);
        $payment=(new \Coleza\Application\Commerce\Payments\PaymentApplicationService($payments,$invoices))->recordManualPayment(new \Coleza\Application\Commerce\Payments\RecordManualPaymentCommand(2,$renewal->getId(),$renewal->getTotalMinor()),2);$before=$services->findServiceById($service->getId())->getNextDueDate();
        $this->db->statement($this->db->getDriverName()==='mysql'?"CREATE TRIGGER reject_renewal_approval_audit BEFORE INSERT ON security_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='audit failure'":"CREATE TRIGGER reject_renewal_approval_audit BEFORE INSERT ON security_audit_logs BEGIN SELECT RAISE(ABORT,'audit failure'); END");
        try{(new \Coleza\Application\Commerce\ManualCommerceApplicationService($this->db))->approvePayment($payment->getId(),3);self::fail('Audit failure ignored');}catch(\PDOException){self::assertSame('pending',$payments->findPaymentById($payment->getId())->getStatus());self::assertSame('unpaid',$invoices->findInvoiceById($renewal->getId())->getStatus());self::assertSame($before,$services->findServiceById($service->getId())->getNextDueDate());self::assertArrayNotHasKey('renewal_settlement',$invoices->findInvoiceById($renewal->getId())->getItems()[0]->getMetadata());}
    }
    public function testChunkedListsPreserveAll501RowsAndUnicodeAmounts(): void
    {
        [, $invoices,$payments]=$this->flowFixture();
        $this->db->transaction(function()use($invoices,$payments):void{for($i=0;$i<501;$i++){$invoices->createInvoice(['user_id'=>2,'currency_code'=>'TRY'],[['description'=>'İstanbul ₺ '.$i,'unit_amount_minor'=>100]]);$payments->recordPayment(['user_id'=>2,'currency_code'=>'TRY','amount_minor'=>100,'payment_method'=>'bank_transfer']);}});
        $this->db->enableQueryLog();$this->db->flushQueryLog();$rows=$invoices->listInvoicesForUser(2);self::assertCount(501,$rows);self::assertSame(50100,array_sum(array_map(fn($row)=>$row->getTotalMinor(),$rows)));self::assertSame('İstanbul ₺ 500',$rows[0]->getItems()[0]->getDescription());self::assertLessThanOrEqual(3,count($this->db->getQueryLog()));
        $this->db->flushQueryLog();$rows=$payments->listPaymentsForUser(2);self::assertCount(501,$rows);self::assertLessThanOrEqual(5,count($this->db->getQueryLog()));self::assertSame([],$rows[0]->getAllocations());
    }
    public function testBatchPaymentListsKeepAllocationsAndRefundAmounts(): void
    {
        $fixture=$this->flowFixture();[, ,$payments]=$fixture;[, ,$payment]=$this->paidFlowOrder($fixture);$payments->recordRefund(['payment_id'=>$payment->getId(),'amount_minor'=>200,'reason'=>'Partial adjustment']);
        $row=$payments->listPaymentsForUser(2)[0];self::assertCount(1,$row->getAllocations());self::assertSame(200,$row->getRefundedAmountMinor());self::assertSame(1240,$row->getRefundableAmountMinor());
    }
    public function testFulfillmentUnitLimitIsRejectedBeforeCustomerCanPay(): void
    {
        [$orders, , , ,$product]=$this->flowFixture();$this->flowActors();
        try{(new \Coleza\Application\Commerce\Orders\OrderApplicationService($orders))->createOrder(new \Coleza\Application\Commerce\Orders\CreateOrderCommand(2,[['product_id'=>$product,'quantity'=>600],['product_id'=>$product,'quantity'=>401]]),2);self::fail('Unfulfillable order accepted');}
        catch(ValidationException){self::assertCount(0,$this->db->select('SELECT * FROM orders'));self::assertCount(0,$this->db->select('SELECT * FROM invoices'));}
    }
    public function testCustomerCannotFulfillOrActivateManualService(): void
    {
        $fixture=$this->flowFixture();$this->flowActors();[, , ,$services]=$fixture;[$order]=$this->paidFlowOrder($fixture);$manual=new \Coleza\Application\Commerce\ManualCommerceApplicationService($this->db);
        try{$manual->fulfillOrder($order->getId(),2);self::fail('Customer fulfilled order');}catch(\Coleza\Foundation\Exceptions\AuthorizationException){self::assertCount(0,$this->db->select('SELECT * FROM services'));}
        $service=$services->createServicesFromOrder($order)[0];
        try{$manual->activateService($service->getId(),2);self::fail('Customer activated service');}catch(\Coleza\Foundation\Exceptions\AuthorizationException){self::assertSame('pending',$services->findServiceById($service->getId())->getStatus());}
    }
}
