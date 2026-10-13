<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
use Coleza\Domain\Analytics\Subscription\SubscriptionSnapshotService;
use Coleza\Domain\Analytics\ReadModels\ReadModelAggregationService;

trait AnalyticsSchemaCases
{
    private function installAnalytics(): void
    {
        SubscriptionSourceFixture::install($this->db);
        DailySourceFixture::install($this->db);
        (new SubscriptionSnapshotService($this->db))->ensureTables();
        (new ReadModelAggregationService($this->db))->ensureTables();
    }
    private function assertAnalyticsNoDdl(): void
    {
        foreach ($this->db->getQueryLog() as $query) {
            self::assertDoesNotMatchRegularExpression('/^\s*(CREATE|ALTER|DROP|TRUNCATE)\b/i', $query['query']);
        }
    }
    public function testInstalledReadsPreserveRawCallerRollback(): void
    {
        $this->installAnalytics(); $this->db->statement('CREATE TABLE analytics_marker (id INT PRIMARY KEY)');
        $this->db->getPdo()->beginTransaction();
        try {
            $this->db->insert('analytics_marker', ['id' => 1]);
            $this->db->enableQueryLog(); $this->db->flushQueryLog();
            self::assertNull((new SubscriptionSnapshotService($this->db))->getSnapshot('2026-10-13'));
            self::assertSame([], (new ReadModelAggregationService($this->db))->getDailySeries('2026-10-01', '2026-10-13'));
            self::assertSame([], (new ReadModelAggregationService($this->db))->getMonthlySeries('2026-01', '2026-10'));
            self::assertTrue($this->db->getPdo()->inTransaction()); $this->assertAnalyticsNoDdl();
        } finally { if ($this->db->getPdo()->inTransaction()) { $this->db->getPdo()->rollBack(); } }
        self::assertSame([], $this->db->select('SELECT * FROM analytics_marker'));
    }
    public function testInstalledSnapshotWritePreservesManagedCallerRollback(): void
    {
        $this->installAnalytics(); $this->db->beginTransaction();
        try {
            (new SubscriptionSnapshotService($this->db))->captureSnapshot('2026-10-13');
            self::assertTrue($this->db->getPdo()->inTransaction());
        } finally { if ($this->db->getPdo()->inTransaction()) { $this->db->rollBack(); } }
        self::assertSame([], $this->db->select('SELECT * FROM analytics_subscription_snapshots'));
    }
    public function testInstalledReadModelWritePreservesRawCallerRollback(): void
    {
        $this->installAnalytics(); $this->db->getPdo()->beginTransaction();
        try {
            (new ReadModelAggregationService($this->db))->rebuildDateRange('2026-10-01', '2026-10-31');
            (new SubscriptionSnapshotService($this->db))->captureSnapshot('2026-10-31');
            (new ReadModelAggregationService($this->db))->aggregateMonth('2026-10');
            self::assertTrue($this->db->getPdo()->inTransaction());
        } finally { if ($this->db->getPdo()->inTransaction()) { $this->db->getPdo()->rollBack(); } }
        self::assertSame([], $this->db->select('SELECT * FROM analytics_daily_aggregations'));
        self::assertSame([], $this->db->select('SELECT * FROM analytics_monthly_aggregations'));
        self::assertSame([], $this->db->select('SELECT * FROM analytics_subscription_snapshots'));
    }
    public function testMissingSchemaRefusesInsideRawCallerBeforeDdl(): void
    {
        $this->db->getPdo()->beginTransaction();
        try {
            foreach ([new SubscriptionSnapshotService($this->db), new ReadModelAggregationService($this->db)] as $service) {
                try { $service->ensureTables(); self::fail('Missing schema created in caller transaction'); }
                catch (\LogicException $error) { self::assertStringContainsString('schema', $error->getMessage()); }
                self::assertTrue($this->db->getPdo()->inTransaction());
            }
        } finally { if ($this->db->getPdo()->inTransaction()) { $this->db->getPdo()->rollBack(); } }
    }
    private function analyticsMigration(): \Coleza\Foundation\Database\MigrationInterface
    {
        return require dirname(__DIR__, 2) . '/database/migrations/2026_10_13_000024_analytics_schema.php';
    }
    private function dropAnalyticsIndex(string $table, string $name): void
    {
        $this->db->statement("DROP INDEX {$name}" . ($this->db->getDriverName() === 'mysql' ? " ON {$table}" : ''));
    }
    private function seedAnalyticsRows(): void
    {
        $this->db->statement("INSERT INTO analytics_subscription_snapshots (snapshot_date, currency, total_mrr, metadata, created_at) VALUES ('2020-01-02', 'TRY', 123.45, ?, '2020-01-02 03:04:05')", ['{"name":"İstanbul 🧪"}']);
        $this->db->statement("INSERT INTO analytics_daily_aggregations (date, currency, gross_invoiced, rebuilt_at) VALUES ('2020-01-02', 'TRY', 234.56, '2020-01-02 03:04:05')");
        $this->db->statement("INSERT INTO analytics_monthly_aggregations (`year_month`, currency, gross_invoiced, rebuilt_at) VALUES ('2020-01', 'TRY', 345.67, '2020-01-02 03:04:05')");
    }
    private function analyticsRows(): array
    {
        $rows = [];
        foreach (['analytics_subscription_snapshots', 'analytics_daily_aggregations', 'analytics_monthly_aggregations'] as $table) { $rows[$table] = $this->db->select("SELECT * FROM {$table} ORDER BY id"); }
        return $rows;
    }
    public function testMigrationReplayPreservesAmountsIdsDatesAndUnicode(): void
    {
        $this->installAnalytics(); $this->seedAnalyticsRows(); $before = $this->analyticsRows();
        $migration = $this->analyticsMigration(); $migration->up($this->db); $migration->up($this->db);
        self::assertSame($before, $this->analyticsRows());
        try { $migration->down($this->db); self::fail('History rollback allowed without maintenance'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('maintenance plan', $error->getMessage()); }
        self::assertSame($before, $this->analyticsRows());
    }
    public function testAllThreePeriodCurrencyKeysRejectDuplicatesButAllowOtherCurrency(): void
    {
        $this->installAnalytics(); $this->seedAnalyticsRows();
        foreach (['analytics_subscription_snapshots' => ['snapshot_date', '2020-01-02'], 'analytics_daily_aggregations' => ['date', '2020-01-02'], 'analytics_monthly_aggregations' => ['year_month', '2020-01']] as $table => [$period, $value]) {
            try { $this->db->statement("INSERT INTO {$table} (`{$period}`, currency) VALUES (?, 'TRY')", [$value]); self::fail('Duplicate period accepted'); }
            catch (\PDOException) { self::assertCount(1, $this->db->select("SELECT * FROM {$table}")); }
            $this->db->statement("INSERT INTO {$table} (`{$period}`, currency) VALUES (?, 'USD')", [$value]);
            self::assertCount(2, $this->db->select("SELECT * FROM {$table}"));
        }
    }
    public function testExplicitMigrationRepairsMissingIndexesWithoutChangingRows(): void
    {
        $this->installAnalytics(); $this->seedAnalyticsRows(); $before = $this->analyticsRows();
        foreach (['analytics_subscription_snapshots' => 'uq_snapshot_date_curr', 'analytics_daily_aggregations' => 'uq_daily_agg', 'analytics_monthly_aggregations' => 'uq_monthly_agg'] as $table => $index) { $this->dropAnalyticsIndex($table, $index); }
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        try { (new SubscriptionSnapshotService($this->db))->getSnapshot('2020-01-02', 'TRY'); self::fail('Missing unique key silently accepted'); }
        catch (\LogicException $error) { self::assertStringContainsString('explicit upgrade required', $error->getMessage()); }
        $this->assertAnalyticsNoDdl();
        $this->analyticsMigration()->up($this->db); self::assertSame($before, $this->analyticsRows());
        self::assertNotNull((new SubscriptionSnapshotService($this->db))->getSnapshot('2020-01-02', 'TRY'));
    }
    public function testDuplicateHistoryStopsBeforeCreatingMissingSiblingTables(): void
    {
        (new SubscriptionSnapshotService($this->db))->ensureTables();
        $this->dropAnalyticsIndex('analytics_subscription_snapshots', 'uq_snapshot_date_curr');
        $this->db->statement("INSERT INTO analytics_subscription_snapshots (snapshot_date, currency) VALUES ('2020-01-02', 'USD'), ('2020-01-02', 'USD')");
        $before = $this->db->select('SELECT * FROM analytics_subscription_snapshots');
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        try { $this->analyticsMigration()->up($this->db); self::fail('Duplicate history was silently merged'); }
        catch (\LogicException $error) { self::assertStringContainsString('reconciliation', $error->getMessage()); }
        $this->assertAnalyticsNoDdl();
        self::assertSame($before, $this->db->select('SELECT * FROM analytics_subscription_snapshots'));
    }
    public function testConflictingIndexIsNotSilentlyAcceptedOrDropped(): void
    {
        $this->installAnalytics(); $this->seedAnalyticsRows(); $before = $this->analyticsRows();
        $this->dropAnalyticsIndex('analytics_monthly_aggregations', 'uq_monthly_agg');
        $this->db->statement('CREATE INDEX uq_monthly_agg ON analytics_monthly_aggregations (currency)');
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        try { $this->analyticsMigration()->up($this->db); self::fail('Wrong index was silently accepted'); }
        catch (\LogicException $error) { self::assertStringContainsString('conflicts', $error->getMessage()); }
        $this->assertAnalyticsNoDdl(); self::assertSame($before, $this->analyticsRows());
    }
    public function testPartialLastTableStopsMigrationBeforeCreatingEarlierTables(): void
    {
        $this->db->statement('CREATE TABLE analytics_monthly_aggregations (id INT PRIMARY KEY, `year_month` VARCHAR(7) NOT NULL, currency VARCHAR(3) NOT NULL)');
        $this->db->statement("INSERT INTO analytics_monthly_aggregations VALUES (7, '2020-01', 'TRY')");
        $before = $this->db->select('SELECT * FROM analytics_monthly_aggregations');
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        try { $this->analyticsMigration()->up($this->db); self::fail('Partial schema automatically repaired'); }
        catch (\LogicException $error) { self::assertStringContainsString('explicit upgrade required', $error->getMessage()); }
        $this->assertAnalyticsNoDdl(); self::assertSame($before, $this->db->select('SELECT * FROM analytics_monthly_aggregations'));
    }
    public function testProductionMigrationsInstallAllThreeTables(): void
    {
        $runner = new \Coleza\Foundation\Database\Migrator($this->db);
        self::assertCount(25, $runner->migrate(dirname(__DIR__, 2) . '/database/migrations'));
        self::assertSame([[], [], []], array_values($this->analyticsRows()));
        self::assertSame([], $runner->migrate(dirname(__DIR__, 2) . '/database/migrations'));
    }
    public function testFailedMigrationCanResumeAfterExplicitEmptyFixtureRepair(): void
    {
        $this->db->statement('CREATE TABLE analytics_monthly_aggregations (id INT PRIMARY KEY)');
        $runner = new \Coleza\Foundation\Database\Migrator($this->db);
        try { $runner->migrate(dirname(__DIR__, 2) . '/database/migrations'); self::fail('Partial schema accepted'); }
        catch (\LogicException) { self::assertCount(23, $runner->getRanMigrations()); }
        // This fixture is empty and owned by the test; production data is never dropped or guessed.
        self::assertSame([], $this->db->select('SELECT * FROM analytics_monthly_aggregations'));
        $this->db->statement('DROP TABLE analytics_monthly_aggregations');
        self::assertSame(['2026_10_13_000024_analytics_schema', '2026_10_13_000025_daily_aggregation_evidence'], $runner->migrate(dirname(__DIR__, 2) . '/database/migrations'));
        self::assertCount(25, $runner->getRanMigrations());
    }
}
