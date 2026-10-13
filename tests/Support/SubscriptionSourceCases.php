<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
use Coleza\Domain\Analytics\Subscription\SubscriptionSnapshotService;

trait SubscriptionSourceCases
{
    private function sourceFixture(): void
    {
        (new \Coleza\Foundation\Database\Migrator($this->db))->migrate(dirname(__DIR__, 2) . '/database/migrations');
        foreach (['USD' => 2, 'EUR' => 2, 'JPY' => 0, 'BHD' => 3] as $code => $places) {
            (new \Coleza\Domain\Finance\Currency\CurrencyService($this->db))->createCurrency(['code' => $code, 'name' => $code, 'symbol' => $code, 'decimal_places' => $places]);
        }
    }
    private function sourceService(int $minor, string $code = 'USD', string $status = 'active', string $cycle = 'monthly', int $user = 1): void
    {
        $this->db->insert('services', ['service_number' => 'SRC-' . bin2hex(random_bytes(6)), 'user_id' => $user, 'product_id' => 1, 'status' => $status, 'billing_cycle' => $cycle, 'recurring_amount_minor' => $minor, 'currency_code' => $code, 'registration_date' => '2026-01-01', 'next_due_date' => '2026-11-01']);
    }
    private function snapshotRows(): array { return $this->db->select('SELECT * FROM analytics_subscription_snapshots ORDER BY id'); }
    private function sourceSnapshot(): SubscriptionSnapshotService { return new SubscriptionSnapshotService($this->db); }
    public function testProductionServicesUseMinorUnitsAndCurrencyIsolation(): void
    {
        $this->sourceFixture();
        $this->sourceService(2000); $this->sourceService(36000, cycle: 'annually', user: 2);
        $this->sourceService(99900, 'EUR'); $this->sourceService(50000, status: 'suspended');
        $this->sourceService(20000, cycle: 'one_time');
        $snapshot = $this->sourceSnapshot()->captureSnapshot('2026-10-13', ' usd ');
        self::assertSame('USD', $snapshot->getCurrency());
        self::assertSame(50.0, $snapshot->getTotalMrr()); self::assertSame(600.0, $snapshot->getTotalArr());
        self::assertSame(2, $snapshot->getActiveSubscriptionsCount()); self::assertSame(2, $snapshot->getActiveCustomersCount());
        self::assertSame(25.0, $snapshot->getArpu());
    }
    public function testMissingSourceCannotOverwriteExistingSnapshotWithZero(): void
    {
        $this->sourceFixture(); $this->db->insert('analytics_subscription_snapshots', ['snapshot_date' => '2026-10-13', 'currency' => 'USD', 'total_mrr' => 77, 'total_arr' => 924]);
        $before = $this->snapshotRows(); $this->db->statement('ALTER TABLE services RENAME TO fixture_saved_services');
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        try { $this->sourceSnapshot()->captureSnapshot('2026-10-13'); self::fail('Missing source overwritten as zero'); }
        catch (\PDOException) { self::assertSame($before, $this->snapshotRows()); }
        foreach ($this->db->getQueryLog() as $query) { self::assertDoesNotMatchRegularExpression('/^\s*(CREATE|ALTER|INSERT|UPDATE|DELETE)\b/i', $query['query']); }
    }
    public function testLegacyHostingTableIsNotAReplacementForCanonicalServices(): void
    {
        $this->sourceFixture(); $this->db->statement('ALTER TABLE services RENAME TO fixture_saved_services');
        $this->db->statement('CREATE TABLE hosting_services (id INT, user_id INT, billing_cycle VARCHAR(30), recurring_amount DECIMAL(12,2), currency VARCHAR(3), status VARCHAR(20), created_at TIMESTAMP)');
        $this->db->statement("INSERT INTO hosting_services VALUES (1, 1, 'monthly', 99, 'USD', 'active', CURRENT_TIMESTAMP)");
        try { $this->sourceSnapshot()->captureSnapshot('2026-10-13'); self::fail('Legacy table silently adopted as current source'); }
        catch (\PDOException) { self::assertSame([], $this->snapshotRows()); }
    }
    public function testUnknownCurrencyRefusesBeforeSnapshotWrite(): void
    {
        $this->sourceFixture();
        try { $this->sourceSnapshot()->captureSnapshot('2026-10-13', 'ZZZ'); self::fail('Unknown currency guessed'); }
        catch (\Coleza\Foundation\Exceptions\ValidationException) { self::assertSame([], $this->snapshotRows()); }
    }
    public function testUnknownCycleCannotBecomeMonthlyRevenue(): void
    {
        $this->sourceFixture(); $this->sourceService(5000, cycle: 'unknown-cycle');
        try { $this->sourceSnapshot()->captureSnapshot('2026-10-13'); self::fail('Unknown cycle assumed monthly'); }
        catch (\Coleza\Foundation\Exceptions\ValidationException) { self::assertSame([], $this->snapshotRows()); }
    }
    public function testZeroDecimalCurrencyDoesNotDivideAmountByOneHundred(): void
    {
        $this->sourceFixture(); $this->sourceService(1500, 'JPY');
        self::assertSame(1500.0, $this->sourceSnapshot()->captureSnapshot('2026-10-13', 'JPY')->getTotalMrr());
    }
    public function testUnsupportedPrecisionIsNotSilentlyRoundedIntoTwoDecimalStorage(): void
    {
        $this->sourceFixture(); $this->sourceService(12345, 'BHD');
        try { $this->sourceSnapshot()->captureSnapshot('2026-10-13', 'BHD'); self::fail('Unsupported precision rounded'); }
        catch (\LogicException $error) { self::assertStringContainsString('precision', $error->getMessage()); self::assertSame([], $this->snapshotRows()); }
    }
    public function testInvalidCalendarDateRefusesBeforeSnapshotWrite(): void
    {
        $this->sourceFixture();
        foreach (['2026-02-30', "2026-10-13\n", "2026-10-\0"] as $date) {
            try { $this->sourceSnapshot()->captureSnapshot($date); self::fail('Invalid date accepted'); }
            catch (\Coleza\Foundation\Exceptions\ValidationException) { self::assertSame([], $this->snapshotRows()); }
        }
    }
    public function testAvailableEmptyCanonicalSourceProducesGenuineZero(): void
    {
        $this->sourceFixture(); $snapshot = $this->sourceSnapshot()->captureSnapshot('2026-10-13', 'USD');
        self::assertSame(0.0, $snapshot->getTotalMrr()); self::assertSame(0, $snapshot->getActiveSubscriptionsCount()); self::assertCount(1, $this->snapshotRows());
    }
    public function testNegativeSourceAmountIsNotSilentlyTreatedAsFree(): void
    {
        $this->sourceFixture(); $this->sourceService(-100);
        try { $this->sourceSnapshot()->captureSnapshot('2026-10-13'); self::fail('Negative source amount became free'); }
        catch (\LogicException $error) { self::assertStringContainsString('reconciliation', $error->getMessage()); self::assertSame([], $this->snapshotRows()); }
    }
    public function testMissingCanonicalAmountColumnCannotOverwriteExistingSnapshot(): void
    {
        $this->sourceFixture(); $this->db->insert('analytics_subscription_snapshots', ['snapshot_date' => '2026-10-13', 'currency' => 'USD', 'total_mrr' => 77]);
        $before = $this->snapshotRows(); $this->db->statement('ALTER TABLE services RENAME COLUMN recurring_amount_minor TO fixture_saved_amount');
        try { $this->sourceSnapshot()->captureSnapshot('2026-10-13'); self::fail('Missing source column became zero'); }
        catch (\PDOException) { self::assertSame($before, $this->snapshotRows()); }
    }
    public function testUnverifiedHistoricalSnapshotCannotBeUsedAsMovementBaseline(): void
    {
        $this->sourceFixture(); $this->sourceService(5000);
        $this->db->insert('analytics_subscription_snapshots', ['snapshot_date' => '2026-10-12', 'currency' => 'USD', 'total_mrr' => 0, 'metadata' => '{}']);
        $before = $this->snapshotRows();
        try { $this->sourceSnapshot()->captureSnapshot('2026-10-13'); self::fail('Legacy zero became verified growth'); }
        catch (\LogicException $error) { self::assertStringContainsString('reconciliation', $error->getMessage()); self::assertSame($before, $this->snapshotRows()); }
    }
    public function testChangedPrecisionHistoricalSnapshotCannotBeCompared(): void
    {
        $this->sourceFixture(); $this->sourceService(1500, 'JPY');
        $this->db->insert('analytics_subscription_snapshots', ['snapshot_date' => '2026-10-12', 'currency' => 'JPY', 'total_mrr' => 15, 'metadata' => '{"source":"services:canonical_minor_units","currency_decimal_places":2}']);
        $before = $this->snapshotRows();
        try { $this->sourceSnapshot()->captureSnapshot('2026-10-13', 'JPY'); self::fail('Different precisions were compared'); }
        catch (\LogicException $error) { self::assertStringContainsString('reconciliation', $error->getMessage()); self::assertSame($before, $this->snapshotRows()); }
    }
}
