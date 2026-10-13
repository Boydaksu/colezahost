<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
use Coleza\Domain\Analytics\ReadModels\ReadModelAggregationService;
trait DailySourceCases
{
    private function dailyService(): ReadModelAggregationService { return new ReadModelAggregationService($this->db); }
    private function dailyRows(): array { return $this->db->select('SELECT * FROM analytics_daily_aggregations ORDER BY id'); }
    private function originalDaily(): array
    {
        $this->db->insert('analytics_daily_aggregations', ['date' => '2026-10-13', 'currency' => 'USD', 'gross_invoiced' => 77, 'rebuilt_at' => '2020-01-01 00:00:00']);
        return $this->dailyRows();
    }
    public function testCanonicalTotalsUseCurrencyStatusesAndFinancialEventDates(): void
    {
        DailySourceFixture::install($this->db);
        DailySourceFixture::invoice($this->db, 15000, 12500, 2500, 'paid');
        DailySourceFixture::invoice($this->db, 20000, 18000, 2000);
        DailySourceFixture::invoice($this->db, 77700, 77700, 0, 'draft');
        DailySourceFixture::invoice($this->db, 88800, 88800, 0, 'cancelled');
        DailySourceFixture::invoice($this->db, 99900, 99900, 0, code: 'EUR');
        DailySourceFixture::payment($this->db, 10000, 450, 'partially_refunded');
        DailySourceFixture::payment($this->db, 5000, 150, 'refunded');
        DailySourceFixture::payment($this->db, 2000, 50);
        DailySourceFixture::payment($this->db, 77700, status: 'pending', paidAt: null);
        DailySourceFixture::payment($this->db, 77700, status: 'failed', paidAt: null);
        DailySourceFixture::payment($this->db, 99900, code: 'EUR');
        DailySourceFixture::payment($this->db, 99900, paidAt: '2026-10-14 00:00:00');
        DailySourceFixture::refund($this->db, 2000); DailySourceFixture::refund($this->db, 5000);
        DailySourceFixture::refund($this->db, 99900, 'EUR'); DailySourceFixture::refund($this->db, 99900, refundedAt: '2026-10-14 00:00:00');
        $daily = $this->dailyService()->aggregateDay('2026-10-13', ' usd ');
        self::assertSame('USD', $daily->getCurrency());
        self::assertSame(350.0, $daily->getGrossInvoiced()); self::assertSame(305.0, $daily->getNetInvoiced()); self::assertSame(45.0, $daily->getTaxBilled());
        self::assertSame(2, $daily->getInvoicesCount()); self::assertSame(170.0, $daily->getCashCollected());
        self::assertSame(70.0, $daily->getCashRefunded()); self::assertSame(100.0, $daily->getNetCashFlow()); self::assertSame(6.5, $daily->getGatewayFees());
    }
    public function testMissingRefundSourceCannotReplaceExistingDailyWithZero(): void
    {
        DailySourceFixture::install($this->db); $before = $this->originalDaily(); $this->db->statement('ALTER TABLE refunds RENAME TO fixture_saved_refunds');
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        try { $this->dailyService()->aggregateDay('2026-10-13'); self::fail('Missing source became zero'); }
        catch (\PDOException) { self::assertSame($before, $this->dailyRows()); }
        foreach ($this->db->getQueryLog() as $query) { self::assertDoesNotMatchRegularExpression('/^\s*(CREATE|ALTER|INSERT|UPDATE|DELETE)\b/i', $query['query']); }
    }
    public function testMissingCanonicalFeeColumnPreservesOriginalDaily(): void
    {
        DailySourceFixture::install($this->db); $before = $this->originalDaily(); $this->db->statement('ALTER TABLE payments RENAME COLUMN fee_minor TO fixture_saved_fee');
        try { $this->dailyService()->aggregateDay('2026-10-13'); self::fail('Missing amount column became zero'); }
        catch (\PDOException) { self::assertSame($before, $this->dailyRows()); }
    }
    public function testAvailableEmptyCanonicalSourcesProduceGenuineZero(): void
    {
        DailySourceFixture::install($this->db); $daily = $this->dailyService()->aggregateDay('2026-10-13');
        self::assertSame(0.0, $daily->getGrossInvoiced()); self::assertSame(0.0, $daily->getNetCashFlow()); self::assertCount(1, $this->dailyRows());
    }
    public function testZeroDecimalCurrencyUsesConfiguredMinorUnitScale(): void
    {
        DailySourceFixture::install($this->db);
        DailySourceFixture::invoice($this->db, 1500, 1500, 0, code: 'JPY');
        DailySourceFixture::payment($this->db, 1500, 15, code: 'JPY'); DailySourceFixture::refund($this->db, 200, 'JPY');
        $daily = $this->dailyService()->aggregateDay('2026-10-13', 'JPY');
        self::assertSame(1500.0, $daily->getGrossInvoiced()); self::assertSame(1500.0, $daily->getCashCollected()); self::assertSame(1300.0, $daily->getNetCashFlow()); self::assertSame(15.0, $daily->getGatewayFees());
    }
    public function testUnknownCurrencyRefusesBeforeWrite(): void
    {
        DailySourceFixture::install($this->db);
        try { $this->dailyService()->aggregateDay('2026-10-13', 'ZZZ'); self::fail('Unknown currency guessed'); }
        catch (\Coleza\Foundation\Exceptions\ValidationException) { self::assertSame([], $this->dailyRows()); }
    }
    public function testUnsupportedPrecisionRefusesBeforeWrite(): void
    {
        DailySourceFixture::install($this->db);
        try { $this->dailyService()->aggregateDay('2026-10-13', 'BHD'); self::fail('Precision silently rounded'); }
        catch (\LogicException $error) { self::assertStringContainsString('precision', $error->getMessage()); self::assertSame([], $this->dailyRows()); }
    }
    public function testSettledPaymentWithoutEventDateCannotDisappearAsZero(): void
    {
        DailySourceFixture::install($this->db); DailySourceFixture::payment($this->db, 5000, paidAt: null); $before = $this->originalDaily();
        try { $this->dailyService()->aggregateDay('2026-10-13'); self::fail('Undated settled money vanished'); }
        catch (\LogicException $error) { self::assertStringContainsString('reconciliation', $error->getMessage()); self::assertSame($before, $this->dailyRows()); }
    }
    public function testNegativeInvoiceSourceIsNotSilentlyReportedAsZero(): void
    {
        DailySourceFixture::install($this->db); DailySourceFixture::invoice($this->db, -100, -100, 0);
        try { $this->dailyService()->aggregateDay('2026-10-13'); self::fail('Negative source accepted'); }
        catch (\LogicException $error) { self::assertStringContainsString('reconciliation', $error->getMessage()); self::assertSame([], $this->dailyRows()); }
    }
    public function testCalendarDateValidationPrecedesWrites(): void
    {
        DailySourceFixture::install($this->db);
        foreach (['2026-02-30', "2026-10-13\n", "2026-10-\0"] as $date) {
            try { $this->dailyService()->aggregateDay($date); self::fail('Invalid date accepted'); }
            catch (\Coleza\Foundation\Exceptions\ValidationException) { self::assertSame([], $this->dailyRows()); }
        }
    }
    public function testMissingCountSourceDoesNotLeavePartlyValidDaily(): void
    {
        DailySourceFixture::install($this->db); $before = $this->originalDaily(); $this->db->statement('ALTER TABLE orders RENAME TO fixture_saved_orders');
        try { $this->dailyService()->aggregateDay('2026-10-13'); self::fail('Missing count source ignored'); }
        catch (\PDOException) { self::assertSame($before, $this->dailyRows()); }
    }
    public function testUnknownFinancialStatesRefuseBeforeWrite(): void
    {
        DailySourceFixture::install($this->db); DailySourceFixture::invoice($this->db, 100, 100, 0, 'unknown');
        try { $this->dailyService()->aggregateDay('2026-10-13'); self::fail('Unknown invoice status hidden'); }
        catch (\LogicException $error) { self::assertStringContainsString('reconciliation', $error->getMessage()); self::assertSame([], $this->dailyRows()); }
        $this->db->statement("UPDATE invoices SET status = 'cancelled'"); DailySourceFixture::payment($this->db, 100, status: 'unknown');
        try { $this->dailyService()->aggregateDay('2026-10-13'); self::fail('Unknown payment status hidden'); }
        catch (\LogicException $error) { self::assertStringContainsString('reconciliation', $error->getMessage()); self::assertSame([], $this->dailyRows()); }
    }
    public function testUndatedPersistedRefundRequiresReconciliation(): void
    {
        DailySourceFixture::install($this->db); DailySourceFixture::refund($this->db, 100);
        $this->db->statement('UPDATE refunds SET refunded_at = NULL');
        try { $this->dailyService()->aggregateDay('2026-10-13'); self::fail('Undated refund disappeared'); }
        catch (\LogicException $error) { self::assertStringContainsString('reconciliation', $error->getMessage()); self::assertSame([], $this->dailyRows()); }
    }
    public function testNegativePaymentFeeCannotBecomeValidGatewayCost(): void
    {
        DailySourceFixture::install($this->db); DailySourceFixture::payment($this->db, 100, -1);
        try { $this->dailyService()->aggregateDay('2026-10-13'); self::fail('Negative fee accepted'); }
        catch (\LogicException $error) { self::assertStringContainsString('reconciliation', $error->getMessage()); self::assertSame([], $this->dailyRows()); }
    }
    public function testCurrencyOrderCountsAndRebuildReplayPreserveIdentity(): void
    {
        DailySourceFixture::install($this->db);
        $this->db->statement("INSERT INTO orders (order_number, user_id, currency_code, created_at) VALUES ('U', 1, 'USD', '2026-10-13 00:00:00'), ('E', 1, 'EUR', '2026-10-13 00:00:00'), ('NEXT', 1, 'USD', '2026-10-14 00:00:00')");
        DailySourceFixture::payment($this->db, 1, paidAt: '2026-10-13 00:00:00');
        $first = $this->dailyService()->aggregateDay('2026-10-13'); $before = $this->dailyRows();
        self::assertSame(1, $first->getNewOrdersCount()); self::assertSame(0.01, $first->getCashCollected());
        $second = $this->dailyService()->aggregateDay('2026-10-13');
        self::assertCount(1, $this->dailyRows()); self::assertSame($before[0]['id'], $this->dailyRows()[0]['id']); self::assertSame($first->getCashCollected(), $second->getCashCollected());
    }
    public function testIssuedInvoiceWithoutDateCannotDisappearAsZero(): void
    {
        DailySourceFixture::install($this->db); DailySourceFixture::invoice($this->db, 5000, 5000, 0, date: ''); $before = $this->originalDaily();
        try { $this->dailyService()->aggregateDay('2026-10-13'); self::fail('Undated issued invoice disappeared'); }
        catch (\LogicException $error) { self::assertStringContainsString('reconciliation', $error->getMessage()); self::assertSame($before, $this->dailyRows()); }
    }
}
