<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;

use Coleza\Domain\Commerce\Invoices\InvoiceService;
use Coleza\Domain\Commerce\Recurring\RenewalInvoiceService;
use Coleza\Domain\Commerce\Services\ServiceService;
use Coleza\Domain\Commerce\Services\Lifecycle\OverdueLifecycleWorkflow;
use Coleza\Foundation\Exceptions\ValidationException;

trait RenewalIntegrityCases
{
    private function renewalFixture(): array
    {
        $services=new ServiceService($this->db);$services->ensureTables();
        $invoices=new InvoiceService($this->db);$invoices->ensureTables();
        $renewals=new RenewalInvoiceService($this->db,$invoices);$renewals->ensureTables();
        $service=$services->createService(['user_id'=>2,'product_id'=>1,'status'=>'active','currency_code'=>'TRY','billing_cycle'=>'monthly','recurring_amount_minor'=>1000,'next_due_date'=>'2026-11-01']);
        return [$services,$invoices,$renewals,new OverdueLifecycleWorkflow($services,$invoices),$service];
    }
    private function renewalPayload($service): array
    {
        return ['service_id'=>$service->getId(),'user_id'=>$service->getUserId(),'organization_id'=>$service->getOrganizationId(),'billing_cycle'=>$service->getBillingCycle(),'currency_code'=>$service->getCurrencyCode(),'recurring_amount_minor'=>$service->getRecurringAmountMinor(),'next_due_date'=>$service->getNextDueDate()];
    }
    public function testPaidRenewalReplayDoesNotAdvanceTwice(): void
    {
        [$services,$invoices,$renewals,$workflow,$service]=$this->renewalFixture();
        $invoice=$renewals->generateRenewalInvoice($this->renewalPayload($service));$invoices->applyPayment($invoice->getId(),1000);
        $workflow->handleInvoicePaid($invoice->getId());$workflow->handleInvoicePaid($invoice->getId());
        self::assertSame('2026-12-01',$services->findServiceById($service->getId())->getNextDueDate());
    }
    public function testForeignPaidInvoiceCannotRenewService(): void
    {
        [$services,$invoices, ,$workflow,$service]=$this->renewalFixture();
        $invoice=$invoices->createInvoice(['user_id'=>3,'currency_code'=>'TRY'],[['unit_amount_minor'=>1000,'service_id'=>$service->getId()]]);$invoices->applyPayment($invoice->getId(),1000);
        $workflow->handleInvoicePaid($invoice->getId());self::assertSame('2026-11-01',$services->findServiceById($service->getId())->getNextDueDate());
    }
    public function testUnrelatedPaidInvoiceCannotRenewService(): void
    {
        [$services,$invoices, ,$workflow,$service]=$this->renewalFixture();
        $invoice=$invoices->createInvoice(['user_id'=>2,'currency_code'=>'TRY'],[['unit_amount_minor'=>1000,'service_id'=>$service->getId()]]);$invoices->applyPayment($invoice->getId(),1000);
        $workflow->handleInvoicePaid($invoice->getId());self::assertSame('2026-11-01',$services->findServiceById($service->getId())->getNextDueDate());
    }
    public function testRenewalPriceComesFromPersistedService(): void
    {
        [, ,$renewals, ,$service]=$this->renewalFixture();$data=$this->renewalPayload($service);$data['recurring_amount_minor']=1;
        self::assertSame(1000,$renewals->generateRenewalInvoice($data)->getTotalMinor());
    }
    public function testRenewalRecordFailureDoesNotLeaveOrphanInvoice(): void
    {
        [, ,$renewals, ,$service]=$this->renewalFixture();
        $this->db->statement($this->db->getDriverName()==='mysql'?"CREATE TRIGGER reject_renewal_record BEFORE INSERT ON renewal_records FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='record failure'":"CREATE TRIGGER reject_renewal_record BEFORE INSERT ON renewal_records BEGIN SELECT RAISE(ABORT,'record failure'); END");
        try{$renewals->generateRenewalInvoice($this->renewalPayload($service));self::fail('Record failure swallowed');}catch(\PDOException){self::assertCount(0,$this->db->select('SELECT * FROM invoices'));self::assertCount(0,$this->db->select('SELECT * FROM invoice_items'));}
    }
    public function testPartialPaymentWaitsForFullSettlementAndReplaysRemainEmpty(): void
    {
        [$services,$invoices,$renewals,$workflow,$service]=$this->renewalFixture();$invoice=$renewals->generateRenewalInvoice($this->renewalPayload($service));
        $invoices->applyPayment($invoice->getId(),400);self::assertSame([],$workflow->handleInvoicePaid($invoice->getId()));self::assertSame('2026-11-01',$services->findServiceById($service->getId())->getNextDueDate());
        $invoices->applyPayment($invoice->getId(),600);self::assertCount(1,$workflow->handleInvoicePaid($invoice->getId()));self::assertSame([],$workflow->handleInvoicePaid($invoice->getId()));
        self::assertSame('2026-12-01',$services->findServiceById($service->getId())->getNextDueDate());
    }
    public function testPreviousPeriodReplayCannotConsumeNextPaidPeriod(): void
    {
        [$services,$invoices,$renewals,$workflow,$service]=$this->renewalFixture();$first=$renewals->generateRenewalInvoice($this->renewalPayload($service));$invoices->applyPayment($first->getId(),1000);$workflow->handleInvoicePaid($first->getId());
        $second=$renewals->generateRenewalInvoice(['service_id'=>$service->getId()]);$invoices->applyPayment($second->getId(),1000);$workflow->handleInvoicePaid($second->getId());
        self::assertSame([],$workflow->handleInvoicePaid($first->getId()));self::assertSame('2027-01-01',$services->findServiceById($service->getId())->getNextDueDate());
        self::assertSame($first->getId(),$renewals->generateRenewalInvoice($this->renewalPayload($service))->getId());
    }
    public function testNonRenewableStatesAndForgedPeriodsAreRejectedWithoutInvoices(): void
    {
        [, ,$renewals, ,$service]=$this->renewalFixture();$data=$this->renewalPayload($service);
        foreach([['user_id'=>3],['organization_id'=>42],['currency_code'=>'USD'],['billing_cycle'=>'annually'],['next_due_date'=>'2027-01-01'],['next_due_date'=>'2026-02-30']]as$override){try{$renewals->generateRenewalInvoice(array_replace($data,$override));self::fail('Forged context accepted');}catch(ValidationException){self::assertCount(0,$this->db->select('SELECT * FROM invoices'));}}
        foreach([['status','terminated'],['status','cancelled'],['status','pending'],['auto_renew',0],['billing_cycle','one_time']]as[$field,$value]){
            $this->db->statement('UPDATE services SET status=?,auto_renew=1,billing_cycle=?',['active','monthly']);$this->db->statement('UPDATE services SET '.$field.'=?',[$value]);
            try{$renewals->generateRenewalInvoice(['service_id'=>$service->getId()]);self::fail('Nonrenewable service accepted');}catch(ValidationException){self::assertCount(0,$this->db->select('SELECT * FROM renewal_records'));}
        }
    }
    public function testPaidInvoiceCannotCrossChangedOwnerCurrencyOrTerminatedState(): void
    {
        [$services,$invoices,$renewals,$workflow,$service]=$this->renewalFixture();$invoice=$renewals->generateRenewalInvoice($this->renewalPayload($service));$invoices->applyPayment($invoice->getId(),1000);
        foreach([['user_id',3],['organization_id',42],['currency_code','USD'],['status','terminated'],['next_due_date','2026-10-01']]as[$field,$value]){
            $this->db->statement('UPDATE services SET user_id=2,organization_id=NULL,currency_code=?,status=?,next_due_date=?',['TRY','active','2026-11-01']);$this->db->statement('UPDATE services SET '.$field.'=?',[$value]);$before=$this->db->select('SELECT * FROM services');
            try{$workflow->handleInvoicePaid($invoice->getId());self::fail('Changed service context accepted');}catch(ValidationException){self::assertSame($before,$this->db->select('SELECT * FROM services'));}
        }
    }
    public function testPolicyCanAdvanceSuspendedPeriodWithoutReactivating(): void
    {
        [$services,$invoices,$renewals,$workflow,$service]=$this->renewalFixture();$services->suspendService($service->getId(),'Manual hold');$invoice=$renewals->generateRenewalInvoice($this->renewalPayload($service));$invoices->applyPayment($invoice->getId(),1000);
        $policy=new \Coleza\Domain\Commerce\Services\Lifecycle\OverdueGracePolicy(allowAutoReactivationOnPayment:false);
        $actions=$workflow->handleInvoicePaid($invoice->getId(),$policy);self::assertCount(1,$actions);$current=$services->findServiceById($service->getId());self::assertSame('suspended',$current->getStatus());self::assertSame('Manual hold',$current->getSuspensionReason());self::assertSame('2026-12-01',$current->getNextDueDate());
    }
    public function testMissingExistingInvoiceFailsClosedWithoutReplacement(): void
    {
        [, ,$renewals, ,$service]=$this->renewalFixture();$invoice=$renewals->generateRenewalInvoice($this->renewalPayload($service));$this->db->statement('DELETE FROM invoice_items');$this->db->statement('DELETE FROM invoices');
        try{$renewals->generateRenewalInvoice($this->renewalPayload($service));self::fail('Missing invoice silently replaced');}catch(ValidationException){self::assertCount(0,$this->db->select('SELECT * FROM invoices'));self::assertCount(1,$this->db->select('SELECT * FROM renewal_records'));}
    }
    public function testSettlementCallerRollbackRestoresPeriodAndReactivation(): void
    {
        [$services,$invoices,$renewals,$workflow,$service]=$this->renewalFixture();$services->suspendService($service->getId(),'Overdue');$invoice=$renewals->generateRenewalInvoice($this->renewalPayload($service));$invoices->applyPayment($invoice->getId(),1000);
        foreach(['raw','managed']as$mode){$before=$this->db->select('SELECT * FROM services');$lines=$this->db->select('SELECT * FROM invoice_items');$operation=function()use($workflow,$invoice):void{self::assertCount(2,$workflow->handleInvoicePaid($invoice->getId()));self::assertTrue($this->db->getPdo()->inTransaction());throw new \RuntimeException('caller rollback');};
            if($mode==='raw'){$this->db->getPdo()->beginTransaction();try{$operation();}catch(\RuntimeException){}finally{$this->db->getPdo()->rollBack();}}
            else{try{$this->db->transaction($operation);}catch(\RuntimeException){}}
            self::assertSame($before,$this->db->select('SELECT * FROM services'));self::assertSame($lines,$this->db->select('SELECT * FROM invoice_items'));
        }
        self::assertCount(2,$workflow->handleInvoicePaid($invoice->getId()));
    }
    public function testGenerationFailurePreservesRawAndManagedCaller(): void
    {
        [, ,$renewals, ,$service]=$this->renewalFixture();
        $this->db->statement($this->db->getDriverName()==='mysql'?"CREATE TRIGGER reject_renewal BEFORE INSERT ON renewal_records FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='record failure'":"CREATE TRIGGER reject_renewal BEFORE INSERT ON renewal_records BEGIN SELECT RAISE(ABORT,'record failure'); END");
        foreach(['raw','managed']as$mode){$operation=function()use($renewals,$service):void{try{$renewals->generateRenewalInvoice($this->renewalPayload($service));self::fail('Failure ignored');}catch(\PDOException){self::assertTrue($this->db->getPdo()->inTransaction());self::assertCount(0,$this->db->select('SELECT * FROM invoices'));}};
            if($mode==='raw'){$this->db->getPdo()->beginTransaction();try{$operation();}finally{$this->db->getPdo()->rollBack();}}else{$this->db->transaction($operation);}
        }
    }
    public function testSettlementWriteFailureLeavesSuspendedPeriodRetryable(): void
    {
        [$services,$invoices,$renewals,$workflow,$service]=$this->renewalFixture();$services->suspendService($service->getId(),'Overdue');$invoice=$renewals->generateRenewalInvoice($this->renewalPayload($service));$invoices->applyPayment($invoice->getId(),1000);$before=$this->db->select('SELECT * FROM services');
        $this->db->statement($this->db->getDriverName()==='mysql'?"CREATE TRIGGER reject_renewal_update BEFORE UPDATE ON services FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='service failure'":"CREATE TRIGGER reject_renewal_update BEFORE UPDATE ON services BEGIN SELECT RAISE(ABORT,'service failure'); END");
        try{$workflow->handleInvoicePaid($invoice->getId());self::fail('Update failure ignored');}catch(\PDOException){self::assertSame($before,$this->db->select('SELECT * FROM services'));}
        $this->db->statement('DROP TRIGGER reject_renewal_update');self::assertCount(2,$workflow->handleInvoicePaid($invoice->getId()));
    }
    public function testBillingPeriodClampsMonthEndAndLeapYearWithoutOverflow(): void
    {
        self::assertSame('2026-02-28',\Coleza\Domain\Commerce\Recurring\BillingPeriod::calculateNextDueDate('2026-01-31','monthly'));
        self::assertSame('2024-02-29',\Coleza\Domain\Commerce\Recurring\BillingPeriod::calculateNextDueDate('2024-01-31','monthly'));
        self::assertSame('2025-02-28',\Coleza\Domain\Commerce\Recurring\BillingPeriod::calculateNextDueDate('2024-02-29','annually'));
    }
    public function testPaymentAutomationUsesBoundPeriodAndCannotReplayOrSwitchService(): void
    {
        [$services,$invoices,$renewals, ,$service]=$this->renewalFixture();$invoice=$renewals->generateRenewalInvoice($this->renewalPayload($service));$invoices->applyPayment($invoice->getId(),1000);
        $handler=new \Coleza\Domain\Automation\Adapters\Service\ServiceRenewActionHandler($services);$context=new \Coleza\Domain\Automation\Triggers\TriggerContext('invoice.paid',['invoice_id'=>$invoice->getId()]);$action=new \Coleza\Domain\Automation\Actions\ActionDefinition('renew','service.renew',['service_id'=>$service->getId()]);
        self::assertTrue($handler->execute($action,$context)->isSuccess());self::assertTrue($handler->execute($action,$context)->isSuccess());self::assertSame('2026-12-01',$services->findServiceById($service->getId())->getNextDueDate());
        $other=$services->createService(['user_id'=>2,'product_id'=>1,'status'=>'active','next_due_date'=>'2026-11-01']);$wrong=new \Coleza\Domain\Automation\Actions\ActionDefinition('wrong','service.renew',['service_id'=>$other->getId()]);self::assertFalse($handler->execute($wrong,$context)->isSuccess());self::assertSame('2026-11-01',$services->findServiceById($other->getId())->getNextDueDate());
        self::assertFalse($handler->execute($action,new \Coleza\Domain\Automation\Triggers\TriggerContext('invoice.paid'))->isSuccess());
    }
    public function testManualDueDateEditDoesNotReplayConsumedInvoice(): void
    {
        [$services,$invoices,$renewals,$workflow,$service]=$this->renewalFixture();$invoice=$renewals->generateRenewalInvoice($this->renewalPayload($service));$invoices->applyPayment($invoice->getId(),1000);$workflow->handleInvoicePaid($invoice->getId());
        $services->updateService($service->getId(),['next_due_date'=>'2026-11-01']);self::assertSame([],$workflow->handleInvoicePaid($invoice->getId()));self::assertSame('2026-11-01',$services->findServiceById($service->getId())->getNextDueDate());
        self::assertSame('applied',$invoices->findInvoiceById($invoice->getId())->getItems()[0]->getMetadata()['renewal_settlement']['result']);
    }
    public function testReceiptWriteFailureRollsBackExtensionAndAllowsRetry(): void
    {
        [$services,$invoices,$renewals,$workflow,$service]=$this->renewalFixture();$services->suspendService($service->getId(),'Overdue');$invoice=$renewals->generateRenewalInvoice($this->renewalPayload($service));$invoices->applyPayment($invoice->getId(),1000);$before=$this->db->select('SELECT * FROM services');$lines=$this->db->select('SELECT * FROM invoice_items');
        $this->db->statement($this->db->getDriverName()==='mysql'?"CREATE TRIGGER reject_renewal_receipt BEFORE UPDATE ON invoice_items FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='receipt failure'":"CREATE TRIGGER reject_renewal_receipt BEFORE UPDATE ON invoice_items BEGIN SELECT RAISE(ABORT,'receipt failure'); END");
        try{$workflow->handleInvoicePaid($invoice->getId());self::fail('Receipt failure ignored');}catch(\PDOException){self::assertSame($before,$this->db->select('SELECT * FROM services'));self::assertSame($lines,$this->db->select('SELECT * FROM invoice_items'));}
        $this->db->statement('DROP TRIGGER reject_renewal_receipt');self::assertCount(2,$workflow->handleInvoicePaid($invoice->getId()));self::assertSame([],$workflow->handleInvoicePaid($invoice->getId()));
    }
}
