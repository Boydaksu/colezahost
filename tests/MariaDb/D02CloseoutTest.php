<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class D02CloseoutTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\D02CloseoutCases;
    public function testNonTransactionalInvoiceItemsRejectedBeforeAnyWrite(): void
    {
        $service = $this->invoiceStorage();
        $this->db->statement('ALTER TABLE invoice_items ENGINE=MyISAM');
        $before = $this->invoiceRows();
        try { $service->createInvoice(['user_id' => 7], [['description' => 'bad storage', 'unit_amount_minor' => 1000]]); self::fail('MyISAM accepted'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('InnoDB', $error->getMessage()); }
        self::assertSame($before, $this->invoiceRows());
    }

}
