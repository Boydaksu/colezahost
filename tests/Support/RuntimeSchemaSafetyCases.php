<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
use Coleza\Foundation\Cache\DatabaseCache;
use Coleza\Foundation\Lock\DatabaseLock;
use Coleza\Foundation\Idempotency\IdempotencyManager;
use Coleza\Foundation\Scheduler\Scheduler;
trait RuntimeSchemaSafetyCases
{
    private function installRuntimeTables(): void
    {
        (new DatabaseCache($this->db))->ensureCacheTable();
        (new DatabaseLock($this->db))->ensureLocksTable();
        (new IdempotencyManager($this->db))->ensureTable();
        (new Scheduler($this->db))->ensureHeartbeatTable();
        (new \Coleza\Foundation\Database\Migrator($this->db))->ensureMigrationsTable();
    }
    private function runtimeOperation(string $type): void
    {
        match ($type) {
            'cache' => (new DatabaseCache($this->db))->set('item', ['value' => 42]),
            'lock' => (new DatabaseLock($this->db))->acquire('item', 60, 'owner'),
            'idempotency' => (new IdempotencyManager($this->db))->execute('item', static fn() => ['value' => 42]),
            'scheduler' => $this->runtimeScheduler()->run(),
            'migration' => (new \Coleza\Foundation\Database\Migrator($this->db))->getRanMigrations(),
        };
    }
    private function runtimeScheduler(): Scheduler
    {
        $scheduler = new Scheduler($this->db);
        $scheduler->call(static fn() => null, 'task')->everyMinute();
        return $scheduler;
    }
    private function assertRuntimeKeepsCallerTransaction(string $type): void
    {
        $this->installRuntimeTables();
        $this->db->statement('CREATE TABLE runtime_guard_marker (id INT PRIMARY KEY)');
        $this->db->beginTransaction();
        try {
            $this->db->insert('runtime_guard_marker', ['id' => 1]);
            $this->runtimeOperation($type);
            $active = $this->db->getPdo()->inTransaction();
        } finally { if ($this->db->getPdo()->inTransaction()) { $this->db->rollBack(); } }
        self::assertTrue($active, 'Runtime schema setup must not commit the caller transaction');
        self::assertSame([], $this->db->select('SELECT * FROM runtime_guard_marker'));
    }
    public function testCacheDoesNotCommitCallerTransaction(): void { $this->assertRuntimeKeepsCallerTransaction('cache'); }
    public function testLockDoesNotCommitCallerTransaction(): void { $this->assertRuntimeKeepsCallerTransaction('lock'); }
    public function testIdempotencyDoesNotCommitCallerTransaction(): void { $this->assertRuntimeKeepsCallerTransaction('idempotency'); }
    public function testSchedulerDoesNotCommitCallerTransaction(): void { $this->assertRuntimeKeepsCallerTransaction('scheduler'); }
    public function testMigrationMetadataReadDoesNotCommitCallerTransaction(): void { $this->assertRuntimeKeepsCallerTransaction('migration'); }
    public function testProductionMigrationsInstallAllFourRuntimeTables(): void
    {
        (new \Coleza\Foundation\Database\Migrator($this->db))->migrate(dirname(__DIR__, 2) . '/database/migrations');
        $missing = [];
        foreach (['locks', 'cache', 'idempotency_keys', 'cron_heartbeats'] as $table) {
            try { $this->db->select("SELECT * FROM {$table} LIMIT 0"); }
            catch (\PDOException) { $missing[] = $table; }
        }
        self::assertSame([], $missing, 'Installed application must not need first-use infrastructure DDL');
    }
    private function assertNoDdl(): void
    {
        foreach ($this->db->getQueryLog() as $query) {
            self::assertDoesNotMatchRegularExpression('/^\s*(CREATE|ALTER|DROP|TRUNCATE)\b/i', $query['query']);
        }
    }
    public function testInstalledRuntimeOperationsIssueNoDdlInsideOrOutsideTransactions(): void
    {
        $this->installRuntimeTables();
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        foreach (['cache', 'lock', 'idempotency', 'scheduler', 'migration'] as $type) { $this->runtimeOperation($type); }
        $this->assertNoDdl();
        $this->db->flushQueryLog(); $this->db->beginTransaction();
        try {
            foreach (['cache', 'lock', 'idempotency', 'scheduler', 'migration'] as $type) { $this->runtimeOperation($type); }
            self::assertTrue($this->db->getPdo()->inTransaction()); $this->assertNoDdl();
        } finally { $this->db->rollBack(); }
    }
    public function testMissingTablesInRawPdoTransactionFailWithoutDdlOrCommitting(): void
    {
        $this->db->statement('CREATE TABLE runtime_guard_marker (id INT PRIMARY KEY)');
        foreach (['cache', 'lock', 'idempotency', 'scheduler', 'migration'] as $type) {
            $this->db->enableQueryLog(); $this->db->flushQueryLog();
            $this->db->getPdo()->beginTransaction();
            try {
                $this->db->insert('runtime_guard_marker', ['id' => 1]);
                try { $this->runtimeOperation($type); self::fail('Missing schema initialized in caller transaction'); }
                catch (\LogicException $e) { self::assertStringContainsString('Initialize complete schema', $e->getMessage()); }
                self::assertTrue($this->db->getPdo()->inTransaction()); $this->assertNoDdl();
            } finally { if ($this->db->getPdo()->inTransaction()) { $this->db->getPdo()->rollBack(); } }
            self::assertSame([], $this->db->select('SELECT * FROM runtime_guard_marker'));
        }
    }
    public function testIncompleteExistingTableRequiresExplicitRepairWithoutChangingData(): void
    {
        $this->db->statement('CREATE TABLE cache (cache_key VARCHAR(255) PRIMARY KEY)');
        $this->db->insert('cache', ['cache_key' => 'preserved']);
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        try { (new DatabaseCache($this->db))->set('item', 42); self::fail('Partial schema silently modified'); }
        catch (\LogicException $e) { self::assertStringContainsString('explicit upgrade required', $e->getMessage()); }
        self::assertSame([['cache_key' => 'preserved']], $this->db->select('SELECT * FROM cache'));
        $this->assertNoDdl();
        $this->db->beginTransaction();
        try {
            try { (new DatabaseCache($this->db))->get('item'); self::fail('Partial table accepted in transaction'); }
            catch (\LogicException) { self::assertTrue($this->db->getPdo()->inTransaction()); }
        } finally { $this->db->rollBack(); }
    }
    public function testMigrationReplayPreservesCacheLocksReplayResultsAndHeartbeat(): void
    {
        $this->installRuntimeTables();
        (new DatabaseCache($this->db))->set('Türkçe', ['value' => 'İstanbul 🧪'], 3600);
        (new DatabaseLock($this->db))->acquire('protected', 3600, 'original-owner');
        $calls = 0;
        $idem = new IdempotencyManager($this->db);
        $idem->execute('completed', function () use (&$calls) { $calls++; return ['value' => 'Ödeme']; });
        $this->runtimeScheduler()->run();
        $before = [];
        foreach (['cache', 'locks', 'idempotency_keys', 'cron_heartbeats', 'migrations'] as $table) { $before[$table] = $this->db->select("SELECT * FROM {$table}"); }
        $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_10_12_000017_foundation_runtime_schema.php';
        $migration->up($this->db); $migration->up($this->db);
        foreach ($before as $table => $rows) { self::assertSame($rows, $this->db->select("SELECT * FROM {$table}")); }
        self::assertSame(['value' => 'İstanbul 🧪'], (new DatabaseCache($this->db))->get('Türkçe'));
        self::assertFalse((new DatabaseLock($this->db))->release('protected', 'wrong-owner'));
        self::assertSame(['value' => 'Ödeme'], (new IdempotencyManager($this->db))->execute('completed', function () use (&$calls) { $calls++; return []; }));
        self::assertSame(1, $calls);
    }
    public function testFailedMigrationIsNotRecordedAndCanResumeAfterExplicitEmptyTableRepair(): void
    {
        $this->db->statement('CREATE TABLE cache (cache_key VARCHAR(255) PRIMARY KEY, cache_value LONGTEXT NOT NULL)');
        $runner = new \Coleza\Foundation\Database\Migrator($this->db);
        try { $runner->migrate(dirname(__DIR__, 2) . '/database/migrations'); self::fail('Incomplete table accepted'); }
        catch (\LogicException) { self::assertCount(16, $runner->getRanMigrations()); }
        self::assertSame([], $this->db->select('SELECT * FROM cache'));
        // Explicit maintenance repair of an empty fixture; no expiry is invented for historical records.
        $this->db->statement('ALTER TABLE cache ADD COLUMN expires_at INT NULL');
        self::assertSame(['2026_10_12_000017_foundation_runtime_schema', '2026_10_12_000018_provider_vault_schema', '2026_10_12_000019_brand_currency_schema', '2026_10_12_000020_configuration_write_locks'], $runner->migrate(dirname(__DIR__, 2) . '/database/migrations'));
        self::assertCount(20, $runner->getRanMigrations());
        self::assertSame([], $runner->migrate(dirname(__DIR__, 2) . '/database/migrations'));
    }
    public function testMissingSchemaRefusesIdempotencyAndSchedulerCallbacksBeforeTheyRun(): void
    {
        $calls = 0;
        $this->db->beginTransaction();
        try {
            try { (new IdempotencyManager($this->db))->execute('new', function () use (&$calls) { $calls++; }); self::fail('Callback ran without schema'); }
            catch (\LogicException) { self::assertSame(0, $calls); }
            $scheduler = new Scheduler($this->db); $scheduler->call(function () use (&$calls) { $calls++; }, 'new');
            try { $scheduler->run(); self::fail('Scheduler ran without schema'); }
            catch (\LogicException) { self::assertSame(0, $calls); }
            self::assertTrue($this->db->getPdo()->inTransaction());
        } finally { $this->db->rollBack(); }
    }

    public function testExplicitSchemaInstallationUsesOnlySqliteTransactionalDdl(): void
    {
        $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_10_12_000017_foundation_runtime_schema.php';
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        $this->db->getPdo()->beginTransaction();
        try {
            if ($this->db->getDriverName() === 'mysql') {
                try { $migration->up($this->db); self::fail('Explicit install bypassed MySQL transaction protection'); }
                catch (\LogicException $error) { self::assertStringContainsString('Initialize complete schema', $error->getMessage()); }
                $this->assertNoDdl();
            } else {
                $migration->up($this->db);
                foreach (['cache', 'locks', 'idempotency_keys', 'cron_heartbeats'] as $table) {
                    self::assertSame([], $this->db->select("SELECT * FROM {$table}"));
                }
            }
            self::assertTrue($this->db->getPdo()->inTransaction());
        } finally { if ($this->db->getPdo()->inTransaction()) { $this->db->getPdo()->rollBack(); } }
        foreach (['cache', 'locks', 'idempotency_keys', 'cron_heartbeats'] as $table) {
            $present = $this->db->getDriverName() === 'sqlite'
                ? $this->db->selectOne("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?", [$table])
                : $this->db->selectOne('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]);
            self::assertNull($present, 'Transaction must not leave installed tables behind');
        }
    }
}
