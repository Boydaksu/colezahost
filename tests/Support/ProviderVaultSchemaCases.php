<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
use Coleza\Domain\Providers\Settings\ProviderSettingService;
use Coleza\Domain\Vault\Encryptor;
use Coleza\Domain\Vault\VaultService;
use Coleza\Foundation\Database\Migrator;
trait ProviderVaultSchemaCases
{
    private function vault(): VaultService { return new VaultService($this->db, new Encryptor(str_repeat('t', 32))); }
    private function settings(): ProviderSettingService { return new ProviderSettingService($this->db, $this->vault()); }
    private function installSettings(): void { $this->settings()->ensureTables(); }
    private function assertCallerRollback(string $service): void
    {
        $this->installSettings();
        $this->db->statement('CREATE TABLE provider_guard_marker (id INT PRIMARY KEY)');
        $this->db->beginTransaction();
        try {
            $this->db->insert('provider_guard_marker', ['id' => 1]);
            if ($service === 'vault') { $this->vault()->setSecret('test', 'token', 'test-secret'); }
            else { $this->settings()->saveSettings('custom-test', ['label' => 'İstanbul', 'api_token' => 'test-secret']); }
            $active = $this->db->getPdo()->inTransaction();
        } finally { if ($this->db->getPdo()->inTransaction()) { $this->db->rollBack(); } }
        self::assertTrue($active, 'Schema DDL must not commit caller data');
        self::assertSame([], $this->db->select('SELECT * FROM provider_guard_marker'));
        self::assertSame([], $this->db->select('SELECT * FROM vault_secrets'));
        self::assertSame([], $this->db->select('SELECT * FROM provider_module_settings'));
    }
    public function testVaultWritePreservesCallerTransaction(): void { $this->assertCallerRollback('vault'); }
    public function testProviderSettingsWritePreservesCallerTransaction(): void { $this->assertCallerRollback('settings'); }
    public function testAuditedVaultWritePreservesCallerTransactionAndAuditRollback(): void
    {
        $this->installSettings();
        $audit = new \Coleza\Domain\Identity\Audit\AuditLogger($this->db);
        $audit->ensureTable();
        $vault = new VaultService($this->db, new Encryptor(str_repeat('t', 32)), $audit);
        $this->db->beginTransaction();
        try {
            $vault->setSecret('test', 'audited', 'test-secret', 42);
            $active = $this->db->getPdo()->inTransaction();
        } finally { if ($this->db->getPdo()->inTransaction()) { $this->db->rollBack(); } }
        self::assertTrue($active, 'Audit dependency must not commit a secret write');
        self::assertSame([], $this->db->select('SELECT * FROM vault_secrets'));
        self::assertSame([], $this->db->select('SELECT * FROM security_audit_logs'));
    }
    public function testProductionMigrationsInstallVaultAndProviderSettings(): void
    {
        (new Migrator($this->db))->migrate(dirname(__DIR__, 2) . '/database/migrations');
        $missing = [];
        foreach (['vault_secrets', 'provider_module_settings'] as $table) {
            try { $this->db->select("SELECT * FROM {$table} LIMIT 0"); }
            catch (\PDOException) { $missing[] = $table; }
        }
        self::assertSame([], $missing, 'Production migration must install both configuration tables without an encryption key');
    }
    private function migration(): \Coleza\Foundation\Database\MigrationInterface
    {
        return require dirname(__DIR__, 2) . '/database/migrations/2026_10_12_000018_provider_vault_schema.php';
    }
    private function tableExists(string $table): bool
    {
        return null !== ($this->db->getDriverName() === 'sqlite'
            ? $this->db->selectOne("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?", [$table])
            : $this->db->selectOne('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]));
    }
    private function assertNoSchemaDdl(): void
    {
        foreach ($this->db->getQueryLog() as $query) { self::assertDoesNotMatchRegularExpression('/^\s*(CREATE|ALTER|DROP|TRUNCATE)\b/i', $query['query']); }
    }
    public function testKeylessMigrationReplayPreservesCiphertextVersionsDatesAndSettings(): void
    {
        $this->installSettings();
        $this->settings()->saveSettings('custom-test', ['label' => 'İstanbul 🧪', 'api_token' => 'initial-test-secret']);
        $this->vault()->setSecret('provider:custom-test', 'api_token', 'rotated-test-secret');
        $before = $this->db->select('SELECT * FROM vault_secrets');
        $settings = $this->db->select('SELECT * FROM provider_module_settings ORDER BY id');
        self::assertSame(2, (int) $before[0]['version']);
        self::assertNotSame('rotated-test-secret', $before[0]['encrypted_value']);
        $this->migration()->up($this->db); $this->migration()->up($this->db);
        self::assertSame($before, $this->db->select('SELECT * FROM vault_secrets'));
        self::assertSame($settings, $this->db->select('SELECT * FROM provider_module_settings ORDER BY id'));
        self::assertSame('rotated-test-secret', $this->settings()->resolveEffectiveConfig('custom-test')->getSecret('api_token'));
        self::assertSame('İstanbul 🧪', $this->settings()->getPublicSettings('custom-test')['label']);
        self::assertNotSame('rotated-test-secret', $this->settings()->getPublicSettings('custom-test')['api_token']);
        self::assertNull($settings[1]['setting_value']);
    }
    public function testInstalledAuditedReadRotateAndDeleteUseNoDdlAndRollbackTogether(): void
    {
        (new Migrator($this->db))->migrate(dirname(__DIR__, 2) . '/database/migrations');
        $audit = new \Coleza\Domain\Identity\Audit\AuditLogger($this->db);
        $vault = new VaultService($this->db, new Encryptor(str_repeat('t', 32)), $audit);
        $settings = new ProviderSettingService($this->db, $vault);
        $settings->saveSettings('custom-test', ['label' => 'original', 'api_token' => 'original-test-secret'], 42);
        $before = [];
        foreach (['vault_secrets', 'provider_module_settings', 'security_audit_logs'] as $table) { $before[$table] = $this->db->select("SELECT * FROM {$table} ORDER BY id"); }
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        self::assertSame('original-test-secret', $vault->getSecret('provider:custom-test', 'api_token', 42));
        $settings->getPublicSettings('custom-test'); $settings->resolveEffectiveConfig('custom-test', 42);
        $this->assertNoSchemaDdl();
        // Read audit events outside a caller transaction are intentionally persistent.
        $before['security_audit_logs'] = $this->db->select('SELECT * FROM security_audit_logs ORDER BY id');
        $this->db->flushQueryLog(); $this->db->beginTransaction();
        try {
            $settings->saveSettings('custom-test', ['label' => 'changed', 'api_token' => 'new-test-secret'], 42);
            self::assertSame('new-test-secret', $settings->resolveEffectiveConfig('custom-test', 42)->getSecret('api_token'));
            self::assertNotSame('new-test-secret', $settings->getPublicSettings('custom-test')['api_token']);
            $settings->deleteSettings('custom-test', 42);
            self::assertSame([], $this->db->select('SELECT * FROM vault_secrets'));
            self::assertSame([], $this->db->select('SELECT * FROM provider_module_settings'));
            self::assertTrue($this->db->getPdo()->inTransaction()); $this->assertNoSchemaDdl();
        } finally { $this->db->rollBack(); }
        foreach ($before as $table => $rows) { self::assertSame($rows, $this->db->select("SELECT * FROM {$table} ORDER BY id")); }
    }
    public function testMissingDependentSchemaInRawTransactionRefusesWritesWithoutDdl(): void
    {
        foreach (['none', 'only-vault', 'only-settings'] as $shape) {
            if ($shape === 'only-vault') { VaultService::ensureSchema($this->db); }
            if ($shape === 'only-settings') {
                $this->installSettings(); $this->db->statement('DROP TABLE vault_secrets');
            }
            $this->db->enableQueryLog(); $this->db->flushQueryLog();
            $this->db->getPdo()->beginTransaction();
            try {
                try { $this->settings()->saveSettings('custom-test', ['api_token' => 'must-not-save']); self::fail('Missing dependency accepted'); }
                catch (\LogicException $error) { self::assertStringContainsString('Initialize complete schema', $error->getMessage()); }
                self::assertTrue($this->db->getPdo()->inTransaction()); $this->assertNoSchemaDdl();
                if ($shape === 'only-vault') { self::assertSame([], $this->db->select('SELECT * FROM vault_secrets')); }
                if ($shape === 'only-settings') { self::assertSame([], $this->db->select('SELECT * FROM provider_module_settings')); }
            } finally { $this->db->getPdo()->rollBack(); }
            foreach (['vault_secrets', 'provider_module_settings'] as $table) { if ($this->tableExists($table)) { $this->db->statement("DROP TABLE {$table}"); } }
        }
    }
    public function testPartialExistingSchemaStopsBeforeCreatingOtherTableOrChangingRows(): void
    {
        foreach (['vault_secrets', 'provider_module_settings'] as $partial) {
            $this->db->statement("CREATE TABLE {$partial} (id INT PRIMARY KEY)");
            $this->db->insert($partial, ['id' => 41]);
            $this->db->enableQueryLog(); $this->db->flushQueryLog();
            try { $this->migration()->up($this->db); self::fail('Partial schema silently repaired'); }
            catch (\LogicException $error) { self::assertStringContainsString('explicit upgrade required', $error->getMessage()); }
            self::assertSame([['id' => 41]], $this->db->select("SELECT * FROM {$partial}"));
            $other = $partial === 'vault_secrets' ? 'provider_module_settings' : 'vault_secrets';
            self::assertFalse($this->tableExists($other)); $this->assertNoSchemaDdl();
            $this->db->statement("DROP TABLE {$partial}");
        }
    }
    public function testFailedConfigurationMigrationCanResumeAfterExplicitFixtureRepair(): void
    {
        $this->db->statement('CREATE TABLE provider_module_settings (id INT PRIMARY KEY, provider_slug VARCHAR(100), setting_key VARCHAR(100), setting_value TEXT, is_secret INT, created_at TIMESTAMP NULL)');
        $this->db->insert('provider_module_settings', ['id' => 71, 'provider_slug' => 'custom-test', 'setting_key' => 'label', 'setting_value' => 'preserved', 'is_secret' => 0, 'created_at' => '2020-01-01 00:00:00']);
        $runner = new Migrator($this->db);
        try { $runner->migrate(dirname(__DIR__, 2) . '/database/migrations'); self::fail('Partial schema accepted'); }
        catch (\LogicException) { self::assertCount(17, $runner->getRanMigrations()); }
        self::assertFalse($this->tableExists('vault_secrets'));
        $before = $this->db->select('SELECT * FROM provider_module_settings');
        // Maintenance fixture repair leaves the unknown historical update time NULL.
        $this->db->statement('ALTER TABLE provider_module_settings ADD COLUMN updated_at TIMESTAMP NULL');
        self::assertSame(['2026_10_12_000018_provider_vault_schema'], $runner->migrate(dirname(__DIR__, 2) . '/database/migrations'));
        self::assertCount(18, $runner->getRanMigrations());
        $after = $this->db->select('SELECT * FROM provider_module_settings');
        self::assertNull($after[0]['updated_at']); unset($after[0]['updated_at']); self::assertSame($before, $after);
        self::assertSame([], $runner->migrate(dirname(__DIR__, 2) . '/database/migrations'));
    }
    public function testExplicitInstallCannotCommitMysqlTransactionAndSqliteSetupRollsBack(): void
    {
        $this->db->getPdo()->beginTransaction();
        try {
            if ($this->db->getDriverName() === 'mysql') {
                try { $this->migration()->up($this->db); self::fail('Explicit setup committed caller transaction'); }
                catch (\LogicException $error) { self::assertStringContainsString('Initialize complete schema', $error->getMessage()); }
            } else {
                $this->migration()->up($this->db);
                self::assertTrue($this->tableExists('vault_secrets')); self::assertTrue($this->tableExists('provider_module_settings'));
            }
            self::assertTrue($this->db->getPdo()->inTransaction());
        } finally { if ($this->db->getPdo()->inTransaction()) { $this->db->getPdo()->rollBack(); } }
        self::assertFalse($this->tableExists('vault_secrets')); self::assertFalse($this->tableExists('provider_module_settings'));
    }
    public function testRollbackRefusalPreservesSettingsCiphertextAndMigrationHistory(): void
    {
        $runner = new Migrator($this->db); $path = dirname(__DIR__, 2) . '/database/migrations';
        $runner->migrate($path);
        $this->settings()->saveSettings('custom-test', ['api_token' => 'preserved-test-secret']);
        $before = [];
        foreach (['vault_secrets', 'provider_module_settings', 'migrations'] as $table) { $before[$table] = $this->db->select("SELECT * FROM {$table} ORDER BY id"); }
        try { $runner->rollback($path); self::fail('Rollback deleted credentials'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('explicit backup and retention plan', $error->getMessage()); }
        foreach ($before as $table => $rows) { self::assertSame($rows, $this->db->select("SELECT * FROM {$table} ORDER BY id")); }
    }
    public function testNativeAuditReadIncludesActorAndImpersonatorWithoutDuplicatesOrOtherUsers(): void
    {
        $audit = new \Coleza\Domain\Identity\Audit\AuditLogger($this->db); $audit->ensureTable();
        $audit->log(42, 'ACTOR');
        $audit->log(7, 'IMPERSONATED', impersonatorUserId: 42);
        $audit->log(7, 'UNRELATED');
        $audit->log(42, 'BOTH', impersonatorUserId: 42);
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        self::assertSame(['BOTH', 'IMPERSONATED', 'ACTOR'], array_column($audit->getLogsForUser(42), 'event_type'));
        self::assertSame([], $audit->getLogsForUser(99));
        $this->assertNoSchemaDdl();
    }
}
