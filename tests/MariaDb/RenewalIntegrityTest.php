<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class RenewalIntegrityTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\RenewalIntegrityCases;
    private function peerRenewalConnection(): \Coleza\Foundation\Database\Connection
    {
        return new \Coleza\Foundation\Database\Connection(new \PDO((string)getenv('COLEZA_TEST_MARIADB_DSN').';dbname='.$this->database,getenv('COLEZA_TEST_MARIADB_USER'),getenv('COLEZA_TEST_MARIADB_PASSWORD')));
    }
    public function testFourWorkersGenerateOneInvoiceAndOnePeriodRecord(): void
    {
        [, , , ,$service]=$this->renewalFixture();$results=$this->runParallel('renewalGenerate',$service->getId());
        self::assertSame(['ok','ok','ok','ok'],array_column($results,'status'));self::assertCount(1,array_unique(array_column($results,'invoice_id')));self::assertCount(1,$this->db->select('SELECT * FROM invoices'));self::assertCount(1,$this->db->select('SELECT * FROM invoice_items'));self::assertCount(1,$this->db->select('SELECT * FROM renewal_records'));
    }
    public function testFourWorkersApplyPaidPeriodOnce(): void
    {
        [$services,$invoices,$renewals, ,$service]=$this->renewalFixture();$invoice=$renewals->generateRenewalInvoice($this->renewalPayload($service));$invoices->applyPayment($invoice->getId(),1000);
        $results=$this->runParallel('renewalSettle',$invoice->getId());self::assertSame(['ok','ok','ok','ok'],array_column($results,'status'));self::assertSame(1,array_sum(array_map(static fn($result):int=>count($result['actions']),$results)));self::assertSame('2026-12-01',$services->findServiceById($service->getId())->getNextDueDate());self::assertSame(2,$services->findServiceById($service->getId())->getLockVersion());
    }
    public function testConcurrentGenerationReplayAndPaidSettlementConservePeriod(): void
    {
        [$services,$invoices,$renewals, ,$service]=$this->renewalFixture();$invoice=$renewals->generateRenewalInvoice($this->renewalPayload($service));$invoices->applyPayment($invoice->getId(),1000);
        $results=$this->runParallel('renewalMixed',$service->getId(),(string)$invoice->getId());self::assertSame(['ok','ok','ok','ok'],array_column($results,'status'));self::assertSame('2026-12-01',$services->findServiceById($service->getId())->getNextDueDate());self::assertCount(1,$this->db->select('SELECT * FROM invoices'));
    }
    public function testOldSnapshotUsesCommittedRenewalPriceWithoutDdl(): void
    {
        [, ,$renewals, ,$service]=$this->renewalFixture();$peer=$this->peerRenewalConnection();$this->db->getPdo()->beginTransaction();
        try{self::assertSame(1000,(int)$this->db->selectOne('SELECT recurring_amount_minor FROM services')['recurring_amount_minor']);$peer->statement('UPDATE services SET recurring_amount_minor=2000');$this->db->enableQueryLog();$this->db->flushQueryLog();self::assertSame(2000,$renewals->generateRenewalInvoice($this->renewalPayload($service))->getTotalMinor());foreach($this->db->getQueryLog()as$query){self::assertDoesNotMatchRegularExpression('/^\s*(CREATE|ALTER|DROP)\b/i',$query['query']);}self::assertTrue($this->db->getPdo()->inTransaction());}finally{$this->db->getPdo()->rollBack();}
        self::assertCount(0,$this->db->select('SELECT * FROM invoices'));self::assertCount(0,$this->db->select('SELECT * FROM renewal_records'));
    }
    public function testOldEmptySnapshotSeesNewPaidRenewalAndCallerCanRollback(): void
    {
        [$services, , ,$workflow,$service]=$this->renewalFixture();$peer=$this->peerRenewalConnection();$this->db->getPdo()->beginTransaction();
        try{self::assertCount(0,$this->db->select('SELECT * FROM renewal_records'));self::assertCount(0,$this->db->select('SELECT * FROM invoice_items'));
            $invoices=new \Coleza\Domain\Commerce\Invoices\InvoiceService($peer);$renewals=new \Coleza\Domain\Commerce\Recurring\RenewalInvoiceService($peer,$invoices);$invoice=$renewals->generateRenewalInvoice(['service_id'=>$service->getId()]);$invoices->applyPayment($invoice->getId(),1000);
            self::assertCount(1,$workflow->handleInvoicePaid($invoice->getId()));self::assertSame('2026-12-01',$services->findServiceById($service->getId())->getNextDueDate());self::assertTrue($this->db->getPdo()->inTransaction());
        }finally{$this->db->getPdo()->rollBack();}
        self::assertSame('2026-11-01',$services->findServiceById($service->getId())->getNextDueDate());
    }
    public function testNontransactionalRenewalStorageRefusesBeforeInvoiceWrite(): void
    {
        [, ,$renewals, ,$service]=$this->renewalFixture();$this->db->statement('ALTER TABLE renewal_records ENGINE=MyISAM');
        try{$renewals->generateRenewalInvoice($this->renewalPayload($service));self::fail('Nontransactional period storage accepted');}catch(\LogicException){self::assertCount(0,$this->db->select('SELECT * FROM invoices'));}
    }
}
