<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class CommerceIntegrityTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\CommerceIntegrityCases;

    private function peerCommerceConnection(): \Coleza\Foundation\Database\Connection
    {
        return new \Coleza\Foundation\Database\Connection(new \PDO((string)getenv('COLEZA_TEST_MARIADB_DSN').';dbname='.$this->database,getenv('COLEZA_TEST_MARIADB_USER'),getenv('COLEZA_TEST_MARIADB_PASSWORD')));
    }

    public function testFourWorkersCreateOnlyOneInvoiceForOrder(): void
    {
        [$orders]=$this->commerceFixture();$order=$orders->createOrder(['user_id'=>2],[['unit_price_minor'=>10000]]);
        $results=$this->runParallel('commerceOrderInvoice',$order->getId());
        self::assertSame(['ok','ok','ok','ok'],array_column($results,'status'));
        self::assertCount(1,array_unique(array_column($results,'invoice_id')));
        self::assertCount(1,$this->db->select('SELECT * FROM invoices'));self::assertCount(1,$this->db->select('SELECT * FROM invoice_items'));
    }

    public function testOldSnapshotUsesCurrentPriceAndTaxWithoutDdlAndParentRollbackRemovesOrder(): void
    {
        [, , , ,$tax,$product]=$this->commerceFixture();$zone=$tax->createTaxZone(['code'=>'TR','name'=>'Turkey','country_codes'=>['TR']]);$tax->createTaxRate(['tax_class_id'=>$tax->getDefaultTaxClass()->getId(),'tax_zone_id'=>$zone->getId(),'name'=>'VAT','rate_percent'=>10]);
        (new \Coleza\Domain\Identity\Rbac\RbacService($this->db))->ensureTables();(new \Coleza\Domain\Identity\Audit\AuditLogger($this->db))->ensureTable();
        $peer=$this->peerCommerceConnection();$this->db->getPdo()->beginTransaction();
        try{
            self::assertSame(10000,(int)$this->db->selectOne('SELECT price_minor FROM price_points')['price_minor']);self::assertSame(10.0,(float)$this->db->selectOne('SELECT rate_percent FROM tax_rates')['rate_percent']);
            $peer->statement('UPDATE price_points SET price_minor=20000');$peer->statement('UPDATE tax_rates SET rate_percent=20');
            $this->db->enableQueryLog();$this->db->flushQueryLog();
            self::assertSame(24000,$this->purchase([['product_id'=>$product]])->getTotalMinor());
            foreach($this->db->getQueryLog()as$query){self::assertDoesNotMatchRegularExpression('/^\s*(CREATE|ALTER|DROP)\b/i',$query['query']);}
            self::assertTrue($this->db->getPdo()->inTransaction());
        }finally{$this->db->getPdo()->rollBack();}
        self::assertCount(0,$this->db->select('SELECT * FROM orders'));self::assertCount(0,$this->db->select('SELECT * FROM security_audit_logs'));
    }

    public function testOldSnapshotCannotPurchaseDeactivatedProduct(): void
    {
        [, , , , ,$product]=$this->commerceFixture();$peer=$this->peerCommerceConnection();
        (new \Coleza\Domain\Identity\Rbac\RbacService($this->db))->ensureTables();(new \Coleza\Domain\Identity\Audit\AuditLogger($this->db))->ensureTable();
        $this->db->getPdo()->beginTransaction();try{
            self::assertSame(1,(int)$this->db->selectOne('SELECT is_active FROM products')['is_active']);$peer->statement('UPDATE products SET is_active=0');
            $this->rejectPurchase([['product_id'=>$product]]);self::assertTrue($this->db->getPdo()->inTransaction());
        }finally{$this->db->getPdo()->rollBack();}
    }
}
