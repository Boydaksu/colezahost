<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class CommerceFlowTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\CommerceFlowCases;
    private function peerFlowConnection(): \Coleza\Foundation\Database\Connection
    {
        return new \Coleza\Foundation\Database\Connection(new \PDO((string)getenv('COLEZA_TEST_MARIADB_DSN').';dbname='.$this->database,getenv('COLEZA_TEST_MARIADB_USER'),getenv('COLEZA_TEST_MARIADB_PASSWORD')));
    }
    public function testCanonicalInstalledManualCommerceJourney(): void
    {
        (new \Coleza\Foundation\Database\Migrator($this->db))->migrate(dirname(__DIR__,2).'/database/migrations');
        self::assertSame(26,(int)$this->db->selectOne('SELECT COUNT(*) AS n FROM migrations')['n']);
        $this->testManualCommerceVerticalUsesCatalogPendingApprovalAndRealRenewal();
    }
    public function testFourWorkersFulfillThreeUnitsOnlyOnce(): void
    {
        $fixture=$this->flowFixture();[$order]=$this->paidFlowOrder($fixture,3);$results=$this->runParallel('commerceFulfill',$order->getId());
        self::assertSame(['ok','ok','ok','ok'],array_column($results,'status'));self::assertCount(3,$results[0]['service_ids']);foreach($results as$result){self::assertSame($results[0]['service_ids'],$result['service_ids']);}self::assertCount(3,$this->db->select('SELECT * FROM services'));
    }
    public function testFourStaffApprovalsAllocateOnce(): void
    {
        [$orders,$invoices,$payments, ,$product]=$this->flowFixture();$this->flowActors();$order=$orders->createOrder(['user_id'=>2],[['product_id'=>$product]]);$invoice=$invoices->createInvoiceFromOrder($order);$payment=(new \Coleza\Application\Commerce\Payments\PaymentApplicationService($payments,$invoices))->recordManualPayment(new \Coleza\Application\Commerce\Payments\RecordManualPaymentCommand(2,$invoice->getId(),$invoice->getTotalMinor()),2);
        $results=$this->runParallel('manualFlowApprove',$payment->getId());self::assertSame(['ok','ok','ok','ok'],array_column($results,'status'));self::assertSame(['completed','completed','completed','completed'],array_column($results,'payment_status'));self::assertCount(1,$this->db->select('SELECT * FROM payment_allocations'));self::assertSame(1440,$invoices->findInvoiceById($invoice->getId())->getPaidAmountMinor());
    }
    public function testOldUnpaidSnapshotSeesCommittedPaymentAndCallerRollbackRemovesServices(): void
    {
        [$orders,$invoices, ,$services,$product]=$this->flowFixture();$order=$orders->createOrder(['user_id'=>2],[['product_id'=>$product,'quantity'=>3]]);$invoice=$invoices->createInvoiceFromOrder($order);$peer=$this->peerFlowConnection();$this->db->getPdo()->beginTransaction();
        try{self::assertSame('pending_payment',$this->db->selectOne('SELECT status FROM orders')['status']);
            $peerInvoices=new \Coleza\Domain\Commerce\Invoices\InvoiceService($peer);$peerPayments=new \Coleza\Domain\Commerce\Payments\PaymentService($peer,$peerInvoices,new \Coleza\Domain\Commerce\Orders\OrderService($peer));$payment=$peerPayments->recordPayment(['user_id'=>2,'invoice_id'=>$invoice->getId(),'currency_code'=>'TRY','amount_minor'=>$invoice->getTotalMinor(),'payment_method'=>'bank_transfer']);$peerPayments->approveManualPayment($payment->getId());
            $this->db->enableQueryLog();$this->db->flushQueryLog();self::assertCount(3,$services->createServicesFromOrder($order));foreach($this->db->getQueryLog()as$query){self::assertDoesNotMatchRegularExpression('/^\s*(CREATE|ALTER|DROP)\b/i',$query['query']);}self::assertTrue($this->db->getPdo()->inTransaction());
        }finally{$this->db->getPdo()->rollBack();}
        self::assertCount(0,$this->db->select('SELECT * FROM services'));
    }
    public function testOldSnapshotRejectsChangedPaidInvoiceOwner(): void
    {
        $fixture=$this->flowFixture();[, , ,$services]=$fixture;[$order]=$this->paidFlowOrder($fixture);$peer=$this->peerFlowConnection();$this->db->getPdo()->beginTransaction();
        try{self::assertSame(2,(int)$this->db->selectOne('SELECT user_id FROM invoices')['user_id']);$peer->statement('UPDATE invoices SET user_id=3');
            try{$services->createServicesFromOrder($order);self::fail('Old owner snapshot fulfilled');}catch(\Coleza\Foundation\Exceptions\ValidationException){self::assertCount(0,$this->db->select('SELECT * FROM services'));self::assertTrue($this->db->getPdo()->inTransaction());}
        }finally{$this->db->getPdo()->rollBack();}
    }
    public function testNontransactionalServiceStorageFailsBeforeFulfillment(): void
    {
        $fixture=$this->flowFixture();[, , ,$services]=$fixture;[$order]=$this->paidFlowOrder($fixture);$this->db->statement('ALTER TABLE services ENGINE=MyISAM');
        try{$services->createServicesFromOrder($order);self::fail('MyISAM fulfillment accepted');}catch(\LogicException){self::assertCount(0,$this->db->select('SELECT * FROM services'));}
    }
}
