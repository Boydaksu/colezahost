<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;

use Coleza\Domain\Analytics\ReadModels\ReadModelAggregationService;
use Coleza\Domain\Analytics\Subscription\SubscriptionSnapshotService;
use LogicException;
use PDOException;
use Coleza\Domain\Analytics\ReadModels\DailyAggregationEvidence;

trait DailyEvidenceCases
{
    private function evidenceFixture(bool $produceDays = true): ReadModelAggregationService
    {
        DailySourceFixture::install($this->db);
        $service = new ReadModelAggregationService($this->db);
        if ($produceDays) { $service->rebuildDateRange('2026-10-01', '2026-10-31'); }
        (new SubscriptionSnapshotService($this->db))->captureSnapshot('2026-10-31');
        return $service;
    }
    private function evidenceMonthlyBefore(): array
    {
        $this->db->statement("INSERT INTO analytics_monthly_aggregations (`year_month`, currency, gross_invoiced, rebuilt_at) VALUES ('2026-10', 'USD', 77, '2020-01-01 00:00:00')");
        return $this->db->select('SELECT * FROM analytics_monthly_aggregations');
    }
    private function assertEvidenceRefused(ReadModelAggregationService $service, array $before): void
    {
        try { $service->aggregateMonth('2026-10'); self::fail('Unverified daily input accepted'); }
        catch (LogicException $error) { self::assertStringContainsString('daily evidence', $error->getMessage()); }
        self::assertSame($before, $this->db->select('SELECT * FROM analytics_monthly_aggregations'));
    }
    public function testCompleteLegacyMonthIsNotAutomaticallyTrusted(): void
    {
        $service = $this->evidenceFixture(false);
        for ($day = 1; $day <= 31; $day++) {
            $this->db->insert('analytics_daily_aggregations', ['date' => sprintf('2026-10-%02d', $day), 'currency' => 'USD', 'gross_invoiced' => 99]);
        }
        $before = $this->evidenceMonthlyBefore();
        $this->assertEvidenceRefused($service, $before);
    }
    public function testChangedStoredFinancialAmountInvalidatesEvidence(): void
    {
        $service = $this->evidenceFixture(); $before = $this->evidenceMonthlyBefore();
        $this->db->statement("UPDATE analytics_daily_aggregations SET gross_invoiced = 123.45 WHERE date = '2026-10-15'");
        $this->assertEvidenceRefused($service, $before);
    }
    public function testMalformedMissingAndOtherPolicyEvidencePreserveMonthly(): void
    {
        $service = $this->evidenceFixture(); $before = $this->evidenceMonthlyBefore();
        foreach ([null, '{}', '{', '[]', '{"policy":"legacy"}', '{"policy":"canonical_financial_events:v1","currency_decimal_places":2,"digest":12}'] as $evidence) {
            $this->db->statement("UPDATE analytics_daily_aggregations SET calculation_evidence = ? WHERE date = '2026-10-15'", [$evidence]);
            $this->assertEvidenceRefused($service, $before);
        }
    }
    public function testEvidenceCannotBeCopiedFromAnotherDayOrCurrency(): void
    {
        $service = $this->evidenceFixture(); $before = $this->evidenceMonthlyBefore();
        $other = $this->db->selectOne("SELECT calculation_evidence FROM analytics_daily_aggregations WHERE date = '2026-10-14'");
        $this->db->statement("UPDATE analytics_daily_aggregations SET calculation_evidence = ? WHERE date = '2026-10-15'", [$other['calculation_evidence']]);
        $this->assertEvidenceRefused($service, $before);
        $service->aggregateDay('2026-10-15', 'EUR');
        $other = $this->db->selectOne("SELECT calculation_evidence FROM analytics_daily_aggregations WHERE date = '2026-10-15' AND currency = 'EUR'");
        $this->db->statement("UPDATE analytics_daily_aggregations SET calculation_evidence = ? WHERE date = '2026-10-15' AND currency = 'USD'", [$other['calculation_evidence']]);
        $this->assertEvidenceRefused($service, $before);
    }
    public function testChangedPrecisionCannotReuseDailyEvidence(): void
    {
        $service = $this->evidenceFixture(); $before = $this->evidenceMonthlyBefore();
        $this->db->statement("UPDATE currencies SET decimal_places = 0 WHERE code = 'USD'");
        $this->assertEvidenceRefused($service, $before);
    }
    public function testChangedCounterAndTimestampAlsoInvalidateEvidence(): void
    {
        $service = $this->evidenceFixture(); $before = $this->evidenceMonthlyBefore();
        $this->db->statement("UPDATE analytics_daily_aggregations SET new_orders_count = 1 WHERE date = '2026-10-15'");
        $this->assertEvidenceRefused($service, $before);
        $service->aggregateDay('2026-10-15');
        $this->db->statement("UPDATE analytics_daily_aggregations SET rebuilt_at = '2020-01-01 00:00:00' WHERE date = '2026-10-15'");
        $this->assertEvidenceRefused($service, $before);
    }
    public function testExplicitCanonicalRebuildReconcilesLegacyRowsWithoutChangingIds(): void
    {
        $service = $this->evidenceFixture(false);
        $id = $this->db->insert('analytics_daily_aggregations', ['date' => '2026-10-15', 'currency' => 'USD', 'gross_invoiced' => 77]);
        DailySourceFixture::invoice($this->db, 12345, 10000, 2345, date: '2026-10-15');
        $service->rebuildDateRange('2026-10-01', '2026-10-31');
        $row = $this->db->selectOne("SELECT * FROM analytics_daily_aggregations WHERE date = '2026-10-15'");
        self::assertSame((int) $id, (int) $row['id']); self::assertNotEmpty($row['calculation_evidence']);
        self::assertSame(123.45, $service->aggregateMonth('2026-10')->getGrossInvoiced());
        $service->aggregateDay('2026-10-15');
        self::assertCount(31, $this->db->select('SELECT * FROM analytics_daily_aggregations'));
    }
    public function testRefundExceedingCollectedCashHasConsistentSignedEvidence(): void
    {
        $service = $this->evidenceFixture();
        DailySourceFixture::refund($this->db, 1234, refundedAt: '2026-10-15 12:00:00');
        $daily = $service->aggregateDay('2026-10-15');
        self::assertSame(-12.34, $daily->getNetCashFlow());
        self::assertSame(-12.34, $service->aggregateMonth('2026-10')->getNetCashFlow());
    }
    public function testFailedDailyStatementPreservesPreviousContentsAndEvidence(): void
    {
        $service = $this->evidenceFixture();
        $before = $this->db->selectOne("SELECT * FROM analytics_daily_aggregations WHERE date = '2026-10-15'");
        DailySourceFixture::invoice($this->db, 12345, 10000, 2345, date: '2026-10-15');
        $trigger = $this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER fixture_fail_evidence BEFORE UPDATE ON analytics_daily_aggregations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture write failure'"
            : "CREATE TRIGGER fixture_fail_evidence BEFORE UPDATE ON analytics_daily_aggregations BEGIN SELECT RAISE(ABORT, 'fixture write failure'); END";
        $this->db->statement($trigger);
        try { $service->aggregateDay('2026-10-15'); self::fail('Failure ignored'); }
        catch (PDOException) { self::assertSame($before, $this->db->selectOne("SELECT * FROM analytics_daily_aggregations WHERE date = '2026-10-15'")); }
    }
    public function testMissingEvidenceSchemaRefusesInsideCallerWithoutDdlOrDataChanges(): void
    {
        $service = $this->evidenceFixture();
        $this->db->statement('ALTER TABLE analytics_daily_aggregations RENAME COLUMN calculation_evidence TO fixture_saved_evidence');
        $before = $this->db->select('SELECT * FROM analytics_daily_aggregations');
        $this->db->getPdo()->beginTransaction();
        try {
            $this->db->enableQueryLog(); $this->db->flushQueryLog();
            try { $service->aggregateDay('2026-10-15'); self::fail('Runtime schema repaired'); }
            catch (LogicException $error) { self::assertStringContainsString('explicit upgrade', $error->getMessage()); }
            self::assertTrue($this->db->getPdo()->inTransaction());
            foreach ($this->db->getQueryLog() as $query) { self::assertDoesNotMatchRegularExpression('/^\s*(CREATE|ALTER|INSERT|UPDATE|DELETE)\b/i', $query['query']); }
        } finally { if ($this->db->getPdo()->inTransaction()) { $this->db->getPdo()->rollBack(); } }
        self::assertSame($before, $this->db->select('SELECT * FROM analytics_daily_aggregations'));
    }
    public function testUpgradeReplayPreservesLegacyMoneyIdsDatesAndLeavesEvidenceNull(): void
    {
        $service = $this->evidenceFixture(false);
        $this->db->statement('ALTER TABLE analytics_daily_aggregations DROP COLUMN calculation_evidence');
        $this->db->insert('analytics_daily_aggregations', ['date' => '2020-01-02', 'currency' => 'USD', 'gross_invoiced' => 77.45, 'rebuilt_at' => '2020-01-02 03:04:05']);
        $before = $this->db->select('SELECT * FROM analytics_daily_aggregations');
        $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_10_13_000025_daily_aggregation_evidence.php';
        $migration->up($this->db); $migration->up($this->db);
        $after = $this->db->select('SELECT * FROM analytics_daily_aggregations');
        foreach ($after as &$row) { self::assertNull($row['calculation_evidence']); unset($row['calculation_evidence']); } unset($row);
        self::assertSame($before, $after);
        try { $migration->down($this->db); self::fail('History evidence dropped'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('maintenance plan', $error->getMessage()); }
    }
    public function testConflictingEvidenceSchemaIsNotSilentlyAcceptedOrRepaired(): void
    {
        $this->evidenceFixture(false);
        $this->db->statement('ALTER TABLE analytics_daily_aggregations DROP COLUMN calculation_evidence');
        $this->db->statement("ALTER TABLE analytics_daily_aggregations ADD COLUMN calculation_evidence VARCHAR(10) NOT NULL DEFAULT 'trusted'");
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        try { DailyAggregationEvidence::install($this->db); self::fail('Trusted default accepted'); }
        catch (LogicException $error) { self::assertStringContainsString('explicit upgrade', $error->getMessage()); }
        foreach ($this->db->getQueryLog() as $query) { self::assertDoesNotMatchRegularExpression('/^\s*(CREATE|ALTER|INSERT|UPDATE|DELETE)\b/i', $query['query']); }
    }
    public function testFailedUpgradeResumesAfterExplicitFixtureRepairWithoutTrustingHistory(): void
    {
        $this->evidenceFixture(false);
        $this->db->statement('ALTER TABLE analytics_daily_aggregations DROP COLUMN calculation_evidence');
        $this->db->statement("DELETE FROM migrations WHERE migration = '2026_10_13_000025_daily_aggregation_evidence'");
        $this->db->statement("ALTER TABLE analytics_daily_aggregations ADD COLUMN calculation_evidence VARCHAR(10) NOT NULL DEFAULT 'trusted'");
        $this->db->insert('analytics_daily_aggregations', ['date' => '2020-01-02', 'currency' => 'USD', 'gross_invoiced' => 77.45]);
        $before = $this->db->select('SELECT * FROM analytics_daily_aggregations');
        $runner = new \Coleza\Foundation\Database\Migrator($this->db);
        try { $runner->migrate(dirname(__DIR__, 2) . '/database/migrations'); self::fail('Conflicting upgrade succeeded'); }
        catch (LogicException) { self::assertCount(25, $runner->getRanMigrations()); }
        self::assertSame($before, $this->db->select('SELECT * FROM analytics_daily_aggregations'));
        // Preserve the test-owned conflicting column; never discard or certify historical content.
        $this->db->statement('ALTER TABLE analytics_daily_aggregations RENAME COLUMN calculation_evidence TO fixture_archived_evidence');
        self::assertSame(['2026_10_13_000025_daily_aggregation_evidence'], $runner->migrate(dirname(__DIR__, 2) . '/database/migrations'));
        self::assertCount(26, $runner->getRanMigrations());
        $row = $this->db->selectOne('SELECT * FROM analytics_daily_aggregations');
        self::assertNull($row['calculation_evidence']); self::assertSame('trusted', $row['fixture_archived_evidence']);
        self::assertSame((int) $before[0]['id'], (int) $row['id']); self::assertSame(77.45, (float) $row['gross_invoiced']);
    }
    public function testEvidenceUpgradeRespectsCallerTransaction(): void
    {
        $this->evidenceFixture(false);
        $this->db->statement('ALTER TABLE analytics_daily_aggregations DROP COLUMN calculation_evidence');
        $this->db->getPdo()->beginTransaction();
        try {
            if ($this->db->getDriverName() === 'mysql') {
                try { DailyAggregationEvidence::install($this->db); self::fail('Implicit commit allowed'); }
                catch (LogicException $error) { self::assertStringContainsString('application transaction', $error->getMessage()); }
            } else { DailyAggregationEvidence::install($this->db); }
            self::assertTrue($this->db->getPdo()->inTransaction());
        } finally { if ($this->db->getPdo()->inTransaction()) { $this->db->getPdo()->rollBack(); } }
        try { DailyAggregationEvidence::assertInstalled($this->db); self::fail('Upgrade survived caller rollback'); }
        catch (LogicException $error) { self::assertStringContainsString('explicit upgrade', $error->getMessage()); }
    }
}
