<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;

use Coleza\Domain\Analytics\ReadModels\ReadModelAggregationService;
use Coleza\Domain\Analytics\Subscription\SubscriptionSnapshotService;
use Coleza\Foundation\Exceptions\ValidationException;
use LogicException;
use PDOException;

trait MonthlySourceCases
{
    private function monthlyFixture(string $month = '2026-10', string $currency = 'USD'): ReadModelAggregationService
    {
        DailySourceFixture::install($this->db);
        $service = new ReadModelAggregationService($this->db);
        $end = (new \DateTimeImmutable($month . '-01'))->format('Y-m-t');
        $service->rebuildDateRange($month . '-01', $end, $currency);
        (new SubscriptionSnapshotService($this->db))->captureSnapshot($end, $currency);
        return $service;
    }
    private function savedMonthly(): array
    {
        $this->db->statement("INSERT INTO analytics_monthly_aggregations (`year_month`, currency, gross_invoiced, end_of_month_mrr, rebuilt_at) VALUES ('2026-10', 'USD', 77, 88, '2020-01-01 00:00:00')");
        return $this->db->select('SELECT * FROM analytics_monthly_aggregations');
    }
    private function assertMonthlyPreserved(array $before): void
    {
        self::assertSame($before, $this->db->select('SELECT * FROM analytics_monthly_aggregations'));
    }
    public function testMissingSnapshotTablePropagatesWithoutReplacingMonthly(): void
    {
        $service = $this->monthlyFixture(); $before = $this->savedMonthly();
        $this->db->statement('ALTER TABLE analytics_subscription_snapshots RENAME TO fixture_saved_snapshots');
        try { $service->aggregateMonth('2026-10'); self::fail('Missing source became zero'); }
        catch (PDOException) { $this->assertMonthlyPreserved($before); }
    }
    public function testMissingSnapshotAmountColumnPreservesMonthly(): void
    {
        $service = $this->monthlyFixture(); $before = $this->savedMonthly();
        $this->db->statement('ALTER TABLE analytics_subscription_snapshots RENAME COLUMN total_arr TO fixture_saved_arr');
        try { $service->aggregateMonth('2026-10'); self::fail('Missing column became zero'); }
        catch (PDOException) { $this->assertMonthlyPreserved($before); }
    }
    public function testStaleSnapshotIsNotMonthEnd(): void
    {
        $service = $this->monthlyFixture(); $before = $this->savedMonthly();
        $this->db->statement("UPDATE analytics_subscription_snapshots SET snapshot_date = '2026-10-30'");
        try { $service->aggregateMonth('2026-10'); self::fail('Stale snapshot accepted'); }
        catch (LogicException $error) { self::assertStringContainsString('month-end', $error->getMessage()); $this->assertMonthlyPreserved($before); }
    }
    public function testUnverifiedAndMalformedSnapshotMetadataPreserveMonthly(): void
    {
        $service = $this->monthlyFixture(); $before = $this->savedMonthly();
        foreach ([null, '{}', '{', '[]', '{"source":"legacy"}'] as $metadata) {
            $this->db->statement('UPDATE analytics_subscription_snapshots SET metadata = ?', [$metadata]);
            try { $service->aggregateMonth('2026-10'); self::fail('Unverified source accepted'); }
            catch (LogicException $error) { self::assertStringContainsString('reconciliation', $error->getMessage()); $this->assertMonthlyPreserved($before); }
        }
    }
    public function testChangedSnapshotPrecisionPreservesMonthly(): void
    {
        $service = $this->monthlyFixture(); $before = $this->savedMonthly();
        $this->db->statement("UPDATE currencies SET decimal_places = 0 WHERE code = 'USD'");
        try { $service->aggregateMonth('2026-10'); self::fail('Different snapshot precision accepted'); }
        catch (LogicException $error) { self::assertStringContainsString('reconciliation', $error->getMessage()); $this->assertMonthlyPreserved($before); }
    }
    public function testPartialDailyCoverageIsNotCompleteMonth(): void
    {
        $service = $this->monthlyFixture(); $before = $this->savedMonthly();
        $this->db->statement("DELETE FROM analytics_daily_aggregations WHERE date = '2026-10-15'");
        try { $service->aggregateMonth('2026-10'); self::fail('Partial month accepted'); }
        catch (LogicException $error) { self::assertStringContainsString('daily coverage', $error->getMessage()); $this->assertMonthlyPreserved($before); }
    }
    public function testInvalidMonthsRefuseBeforeSchemaOrWrites(): void
    {
        $service = new ReadModelAggregationService($this->db);
        foreach (['2026-00', '2026-13', '2026-1', "2026-10\n", "2026-10\0"] as $month) {
            try { $service->aggregateMonth($month); self::fail('Invalid month accepted'); }
            catch (ValidationException $error) { self::assertStringContainsString('YYYY-MM', $error->getMessage()); }
        }
    }
    public function testMissingMonthEndSnapshotAndForeignCurrencyCannotSupplyBalance(): void
    {
        $service = $this->monthlyFixture(); $before = $this->savedMonthly();
        $this->db->statement("UPDATE analytics_subscription_snapshots SET currency = 'EUR'");
        try { $service->aggregateMonth('2026-10'); self::fail('Foreign currency supplied balance'); }
        catch (LogicException $error) { self::assertStringContainsString('month-end', $error->getMessage()); $this->assertMonthlyPreserved($before); }
    }
    public function testDailyRowCountAloneCannotProveCalendarCoverage(): void
    {
        $service = $this->monthlyFixture(); $before = $this->savedMonthly();
        $this->db->statement("UPDATE analytics_daily_aggregations SET date = '2026-10-1x' WHERE date = '2026-10-15'");
        try { $service->aggregateMonth('2026-10'); self::fail('Invalid replacement day accepted'); }
        catch (LogicException $error) { self::assertStringContainsString('daily coverage', $error->getMessage()); $this->assertMonthlyPreserved($before); }
    }
    public function testNegativeSnapshotAmountCannotReplaceMonthly(): void
    {
        $service = $this->monthlyFixture(); $before = $this->savedMonthly();
        $this->db->statement('UPDATE analytics_subscription_snapshots SET total_mrr = -1');
        try { $service->aggregateMonth('2026-10'); self::fail('Negative balance accepted'); }
        catch (LogicException $error) { self::assertStringContainsString('reconciliation', $error->getMessage()); $this->assertMonthlyPreserved($before); }
    }
    public function testMonthlyStorageOverflowCannotReplaceMonthly(): void
    {
        $service = $this->monthlyFixture(); $before = $this->savedMonthly();
        $this->db->statement('UPDATE analytics_daily_aggregations SET gross_invoiced = 9999999999.99');
        try { $service->aggregateMonth('2026-10'); self::fail('Monthly overflow accepted'); }
        catch (LogicException $error) { self::assertStringContainsString('capacity', $error->getMessage()); $this->assertMonthlyPreserved($before); }
    }
    public function testUnknownAndUnsupportedCurrenciesPreserveMonthly(): void
    {
        $service = $this->monthlyFixture(); $before = $this->savedMonthly();
        try { $service->aggregateMonth('2026-10', 'ZZZ'); self::fail('Unknown currency accepted'); }
        catch (ValidationException) { $this->assertMonthlyPreserved($before); }
        try { $service->aggregateMonth('2026-10', 'BHD'); self::fail('Precision silently rounded'); }
        catch (LogicException $error) { self::assertStringContainsString('precision', $error->getMessage()); $this->assertMonthlyPreserved($before); }
    }
    public function testCompleteLeapFebruaryAndCanonicalZeroCanBeReplayed(): void
    {
        $service = $this->monthlyFixture('2024-02', 'JPY');
        $first = $service->aggregateMonth('2024-02', ' jpy ');
        $again = $service->aggregateMonth('2024-02', 'JPY');
        self::assertSame('JPY', $first->getCurrency()); self::assertSame(0.0, $first->getGrossInvoiced());
        self::assertSame(0.0, $first->getEndOfMonthMrr()); self::assertSame($first->getId(), $again->getId());
        self::assertCount(29, $this->db->select('SELECT * FROM analytics_daily_aggregations'));
        self::assertCount(1, $this->db->select('SELECT * FROM analytics_monthly_aggregations'));
    }
}
