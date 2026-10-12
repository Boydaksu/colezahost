<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
use Coleza\Domain\Brand\BrandService;
use Coleza\Domain\Finance\Currency\CurrencyService;
use Coleza\Foundation\Database\Migrator;
trait BrandCurrencySchemaCases
{
    private function installBrandCurrency(): void
    {
        (new BrandService($this->db))->ensureTable();
        (new CurrencyService($this->db))->ensureTables();
    }
    public function testBrandRuntimeWriteDoesNotCommitCallerTransaction(): void
    {
        $brand = new BrandService($this->db); $brand->getActiveBrand(['name' => 'Original']);
        $before = $this->db->select('SELECT * FROM brands');
        $this->db->statement('CREATE TABLE brand_currency_marker (id INT PRIMARY KEY)');
        $this->db->beginTransaction();
        try {
            $this->db->insert('brand_currency_marker', ['id' => 1]);
            $brand->updateActiveBrand(['name' => 'Changed']);
            $active = $this->db->getPdo()->inTransaction();
        } finally { if ($this->db->getPdo()->inTransaction()) { $this->db->rollBack(); } }
        self::assertTrue($active, 'Brand schema setup must not commit caller data');
        self::assertSame([], $this->db->select('SELECT * FROM brand_currency_marker'));
        self::assertSame($before, $this->db->select('SELECT * FROM brands'));
    }
    public function testCurrencySchemaCheckDoesNotCommitCallerTransaction(): void
    {
        $currency = new CurrencyService($this->db); $currency->ensureTables();
        $this->db->statement('CREATE TABLE brand_currency_marker (id INT PRIMARY KEY)');
        $this->db->beginTransaction();
        try {
            $this->db->insert('brand_currency_marker', ['id' => 1]); $currency->ensureTables();
            $currency->createCurrency(['code' => 'TRY', 'name' => 'Türk Lirası', 'symbol' => '₺', 'is_default' => true]);
            $active = $this->db->getPdo()->inTransaction();
        } finally { if ($this->db->getPdo()->inTransaction()) { $this->db->rollBack(); } }
        self::assertTrue($active, 'Currency schema setup must not commit caller data');
        self::assertSame([], $this->db->select('SELECT * FROM brand_currency_marker'));
        self::assertSame([], $this->db->select('SELECT * FROM currencies'));
    }
    public function testProductionMigrationsInstallCurrencyAndFxWithoutSeedingDefaults(): void
    {
        (new Migrator($this->db))->migrate(dirname(__DIR__, 2) . '/database/migrations');
        $missing = [];
        foreach (['brands', 'currencies', 'fx_snapshots'] as $table) {
            try { $this->db->select("SELECT * FROM {$table} LIMIT 0"); }
            catch (\PDOException) { $missing[] = $table; }
        }
        self::assertSame([], $missing, 'Production migrations must include currency and FX schema');
        foreach (['brands', 'currencies', 'fx_snapshots'] as $table) { self::assertSame([], $this->db->select("SELECT * FROM {$table}")); }
    }
    private function migration(): \Coleza\Foundation\Database\MigrationInterface
    {
        return require dirname(__DIR__, 2) . '/database/migrations/2026_10_12_000019_brand_currency_schema.php';
    }
    private function tableExists(string $table): bool
    {
        return null !== ($this->db->getDriverName() === 'sqlite'
            ? $this->db->selectOne("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?", [$table])
            : $this->db->selectOne('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]));
    }
    private function assertNoDdl(): void
    {
        foreach ($this->db->getQueryLog() as $query) { self::assertDoesNotMatchRegularExpression('/^\s*(CREATE|ALTER|DROP|TRUNCATE)\b/i', $query['query']); }
    }
    private function currencyWithStaticRates(): CurrencyService
    {
        $provider = new \Coleza\Domain\Finance\Fx\StaticFxRateProvider(['EUR' => ['USD' => 1.25]]);
        return new CurrencyService($this->db, ['static' => $provider]);
    }
    private function createFixtureCurrencies(CurrencyService $currency): void
    {
        $currency->createCurrency(['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'is_default' => true]);
        $currency->createCurrency(['code' => 'USD', 'name' => 'Dollar', 'symbol' => '$']);
    }
    public function testInstalledBrandAndCurrencyWorkflowUsesNoDdlAndRollsBack(): void
    {
        (new Migrator($this->db))->migrate(dirname(__DIR__, 2) . '/database/migrations');
        $brand = new BrandService($this->db); $brand->getActiveBrand(['name' => 'İstanbul 🧪']);
        $currency = $this->currencyWithStaticRates(); $this->createFixtureCurrencies($currency);
        $currency->captureFxSnapshot('static', '2020-01-01');
        $before = [];
        foreach (['brands', 'currencies', 'fx_snapshots'] as $table) { $before[$table] = $this->db->select("SELECT * FROM {$table}"); }
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        $brand->getActiveBrand(); $currency->ensureTables(); $currency->listCurrencies();
        self::assertSame('EUR', $currency->getDefaultCurrency()->getCode()); $this->assertNoDdl();
        $this->db->flushQueryLog(); $this->db->beginTransaction();
        try {
            $brand->updateActiveBrand(['name' => 'Changed', 'settings' => ['theme' => 'dark']]);
            $currency->ensureTables();
            $currency->createCurrency(['code' => 'JPY', 'name' => 'Yen', 'symbol' => '¥', 'decimal_places' => 0]);
            $snapshot = $currency->captureFxSnapshot('static', '2020-02-01');
            self::assertSame(1.25, $snapshot->getRate('USD'));
            self::assertSame($snapshot->getId(), $currency->getSnapshotByDate('2020-02-01', 'EUR')->getId());
            self::assertSame($snapshot->getId(), $currency->getLatestFxSnapshot('EUR')->getId());
            self::assertTrue($this->db->getPdo()->inTransaction()); $this->assertNoDdl();
        } finally { $this->db->rollBack(); }
        foreach ($before as $table => $rows) { self::assertSame($rows, $this->db->select("SELECT * FROM {$table}")); }
    }
    public function testMigrationReplayPreservesBrandSettingsCurrencyPrecisionAndRawFxHistory(): void
    {
        $this->installBrandCurrency();
        $this->db->insert('brands', ['id' => 51, 'name' => 'İstanbul 🧪', 'code' => 'custom', 'domain' => 'example.test', 'default_locale' => 'en', 'default_currency' => 'JPY', 'is_active' => 1, 'settings' => '{"theme":"özgün","nested":{"a":1}}', 'created_at' => '2020-01-01 00:00:00']);
        $this->db->insert('brands', ['id' => 52, 'name' => 'Archived', 'code' => 'archived', 'is_active' => 0]);
        (new CurrencyService($this->db))->createCurrency(['code' => 'JPY', 'name' => 'Yen', 'symbol' => '¥', 'decimal_places' => 0, 'is_default' => true]);
        (new CurrencyService($this->db))->createCurrency(['code' => 'BHD', 'name' => 'Dinar', 'symbol' => 'BD', 'decimal_places' => 3, 'is_active' => false]);
        $this->db->insert('fx_snapshots', ['id' => 71, 'base_currency' => 'JPY', 'rates_json' => '{"USD":"0.00710000","BHD":0.0027}', 'provider' => 'legacy-test', 'snapshot_date' => '2020-01-01', 'created_at' => '2020-01-01 01:00:00']);
        $before = [];
        foreach (['brands', 'currencies', 'fx_snapshots'] as $table) { $before[$table] = $this->db->select("SELECT * FROM {$table}"); }
        $this->migration()->up($this->db); $this->migration()->up($this->db);
        foreach ($before as $table => $rows) { self::assertSame($rows, $this->db->select("SELECT * FROM {$table}")); }
        self::assertSame('JPY', (new CurrencyService($this->db))->getDefaultCurrency()->getCode());
        self::assertSame('özgün', (new BrandService($this->db))->getActiveBrand()['settings']['theme']);
    }
    public function testMissingSchemaInRawTransactionRefusesSetupWithoutDdlOrSeeding(): void
    {
        $this->db->enableQueryLog(); $this->db->flushQueryLog(); $this->db->getPdo()->beginTransaction();
        try {
            foreach (['brand', 'currency'] as $type) {
                try {
                    if ($type === 'brand') { (new BrandService($this->db))->getActiveBrand(); }
                    else { (new CurrencyService($this->db))->ensureTables(); }
                    self::fail('Missing schema initialized inside caller transaction');
                } catch (\LogicException $error) { self::assertStringContainsString('Initialize complete schema', $error->getMessage()); }
            }
            self::assertTrue($this->db->getPdo()->inTransaction()); $this->assertNoDdl();
        } finally { $this->db->getPdo()->rollBack(); }
        foreach (['brands', 'currencies', 'fx_snapshots'] as $table) { self::assertFalse($this->tableExists($table)); }
    }
    public function testPartialSiblingStopsMigrationBeforeCreatingAnyOtherTable(): void
    {
        foreach (['brands', 'currencies', 'fx_snapshots'] as $partial) {
            $column = $partial === 'currencies' ? 'code' : 'id';
            $type = $partial === 'currencies' ? 'VARCHAR(3)' : 'INT';
            $value = $partial === 'currencies' ? 'JPY' : 71;
            $this->db->statement("CREATE TABLE {$partial} ({$column} {$type} PRIMARY KEY)");
            $this->db->insert($partial, [$column => $value]);
            $before = $this->db->select("SELECT * FROM {$partial}");
            $this->db->enableQueryLog(); $this->db->flushQueryLog();
            try { $this->migration()->up($this->db); self::fail('Partial schema silently accepted'); }
            catch (\LogicException $error) { self::assertStringContainsString('explicit upgrade required', $error->getMessage()); }
            foreach (['brands', 'currencies', 'fx_snapshots'] as $table) { self::assertSame($table === $partial, $this->tableExists($table)); }
            self::assertSame($before, $this->db->select("SELECT * FROM {$partial}")); $this->assertNoDdl();
            $this->db->statement("DROP TABLE {$partial}");
        }
    }
    public function testFailedMigrationCanResumeAfterExplicitHistoricalFixtureRepair(): void
    {
        $this->db->statement('CREATE TABLE fx_snapshots (id INT PRIMARY KEY, base_currency VARCHAR(3), rates_json TEXT, provider VARCHAR(100), snapshot_date VARCHAR(20))');
        $this->db->insert('fx_snapshots', ['id' => 71, 'base_currency' => 'JPY', 'rates_json' => '{"USD":"0.00710000"}', 'provider' => 'legacy-test', 'snapshot_date' => '2020-01-01']);
        $before = $this->db->select('SELECT * FROM fx_snapshots'); $runner = new Migrator($this->db);
        try { $runner->migrate(dirname(__DIR__, 2) . '/database/migrations'); self::fail('Partial FX schema accepted'); }
        catch (\LogicException) { self::assertCount(18, $runner->getRanMigrations()); }
        self::assertFalse($this->tableExists('currencies'));
        // Explicit fixture maintenance keeps the unknown historical timestamp NULL.
        $this->db->statement('ALTER TABLE fx_snapshots ADD COLUMN created_at TIMESTAMP NULL');
        self::assertSame(['2026_10_12_000019_brand_currency_schema', '2026_10_12_000020_configuration_write_locks', '2026_10_12_000021_abuse_schema'], $runner->migrate(dirname(__DIR__, 2) . '/database/migrations'));
        self::assertCount(21, $runner->getRanMigrations());
        $after = $this->db->select('SELECT * FROM fx_snapshots'); self::assertNull($after[0]['created_at']);
        unset($after[0]['created_at']); self::assertSame($before, $after);
        self::assertSame([], $runner->migrate(dirname(__DIR__, 2) . '/database/migrations'));
    }
    public function testExplicitInstallationCannotCommitMysqlAndSqliteSchemaRollsBack(): void
    {
        $this->db->enableQueryLog(); $this->db->flushQueryLog(); $this->db->getPdo()->beginTransaction();
        try {
            if ($this->db->getDriverName() === 'mysql') {
                try { $this->migration()->up($this->db); self::fail('Explicit setup bypassed MySQL transaction guard'); }
                catch (\LogicException $error) { self::assertStringContainsString('Initialize complete schema', $error->getMessage()); }
                $this->assertNoDdl();
            } else {
                $this->migration()->up($this->db);
                foreach (['brands', 'currencies', 'fx_snapshots'] as $table) { self::assertSame([], $this->db->select("SELECT * FROM {$table}")); }
            }
            self::assertTrue($this->db->getPdo()->inTransaction());
        } finally { if ($this->db->getPdo()->inTransaction()) { $this->db->getPdo()->rollBack(); } }
        foreach (['brands', 'currencies', 'fx_snapshots'] as $table) { self::assertFalse($this->tableExists($table)); }
    }
    public function testCoreInstallerBrandDefaultsRemainUnchanged(): void
    {
        (new \Coleza\Domain\Installer\DatabaseSetupService())->initializeCoreSchema($this->db);
        $before = $this->db->getDriverName() === 'sqlite' ? $this->db->select('PRAGMA table_info(brands)') : $this->db->select('SHOW COLUMNS FROM brands');
        $this->migration()->up($this->db);
        $after = $this->db->getDriverName() === 'sqlite' ? $this->db->select('PRAGMA table_info(brands)') : $this->db->select('SHOW COLUMNS FROM brands');
        self::assertSame($before, $after);
        self::assertSame([], $this->db->select('SELECT * FROM brands'));
    }
    public function testRollbackRefusalPreservesBrandCurrencyFxAndMigrationHistory(): void
    {
        $runner = new Migrator($this->db); $path = dirname(__DIR__, 2) . '/database/migrations'; $runner->migrate($path);
        (new BrandService($this->db))->getActiveBrand(['name' => 'Preserved']);
        $currency = $this->currencyWithStaticRates(); $this->createFixtureCurrencies($currency); $currency->captureFxSnapshot('static', '2020-01-01');
        $before = [];
        foreach (['brands', 'currencies', 'fx_snapshots', 'migrations'] as $table) { $before[$table] = $this->db->select("SELECT * FROM {$table}"); }
        try { $runner->rollback($path); self::fail('Rollback deleted financial history'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('explicit maintenance plan', $error->getMessage()); }
        foreach ($before as $table => $rows) { self::assertSame($rows, $this->db->select("SELECT * FROM {$table}")); }
    }
    public function testMissingFxSchemaStopsBeforeFetchingRatesOrSeedingDefaultCurrency(): void
    {
        $this->installBrandCurrency(); $this->db->statement('DROP TABLE fx_snapshots');
        $provider = new class implements \Coleza\Domain\Finance\Fx\FxRateProviderInterface {
            public int $calls = 0;
            public function getName(): string { return 'counting-test'; }
            public function getRates(string $baseCurrency, array $targetCurrencies): array { $this->calls++; return []; }
        };
        $currency = new CurrencyService($this->db, ['counting-test' => $provider]);
        $this->db->enableQueryLog(); $this->db->flushQueryLog(); $this->db->getPdo()->beginTransaction();
        try {
            $caught = null;
            try { $currency->captureFxSnapshot('counting-test', '2020-01-01'); self::fail('Missing FX schema accepted'); }
            catch (\PDOException|\LogicException $error) { $caught = $error; }
            self::assertSame(0, $provider->calls, 'Schema failure must be detected before invoking rate provider');
            self::assertInstanceOf(\LogicException::class, $caught);
            self::assertSame([], $this->db->select('SELECT * FROM currencies'), 'Schema failure must not seed a fallback TRY currency');
            self::assertTrue($this->db->getPdo()->inTransaction()); $this->assertNoDdl();
        } finally { if ($this->db->getPdo()->inTransaction()) { $this->db->getPdo()->rollBack(); } }
    }
}
