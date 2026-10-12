<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
use Coleza\Domain\Brand\BrandService;
use Coleza\Domain\Finance\Currency\CurrencyService;
use Coleza\Foundation\Exceptions\ValidationException;
trait ConfigurationSingletonCases
{
    private function singletonSchema(): void
    {
        (new BrandService($this->db))->ensureTable();
        (new CurrencyService($this->db))->ensureTables();
    }
    private function currencyFixture(string $code, bool $default = false): array
    {
        return ['code' => $code, 'name' => $code, 'symbol' => $code, 'is_default' => $default];
    }
    public function testFailedDefaultInsertKeepsPreviousDefaultAndAllRows(): void
    {
        $this->singletonSchema(); $currency = new CurrencyService($this->db);
        $currency->createCurrency($this->currencyFixture('USD', true));
        $before = $this->db->select('SELECT * FROM currencies');
        $sql = $this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER reject_currency_insert BEFORE INSERT ON currencies FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected currency insert'"
            : "CREATE TRIGGER reject_currency_insert BEFORE INSERT ON currencies BEGIN SELECT RAISE(ABORT, 'Injected currency insert'); END";
        $this->db->statement($sql);
        try { $currency->createCurrency($this->currencyFixture('EUR', true)); self::fail('Injected insert succeeded'); }
        catch (\PDOException $error) { self::assertStringContainsString('Injected currency insert', $error->getMessage()); }
        self::assertSame($before, $this->db->select('SELECT * FROM currencies'));
    }
    public function testAmbiguousActiveBrandsAreRejectedWithoutChoosingOrChangingOne(): void
    {
        $this->singletonSchema();
        foreach (['one', 'two'] as $code) { $this->db->insert('brands', ['name' => $code, 'code' => $code]); }
        $before = $this->db->select('SELECT * FROM brands ORDER BY id'); $caught = null;
        try { (new BrandService($this->db))->updateActiveBrand(['name' => 'Must not change']); }
        catch (ValidationException $error) { $caught = $error; }
        self::assertNotNull($caught, 'Multiple active brands must require explicit reconciliation');
        self::assertSame($before, $this->db->select('SELECT * FROM brands ORDER BY id'));
    }
    public function testMultipleDefaultCurrenciesRequireExplicitReconciliation(): void
    {
        $this->singletonSchema(); $currency = new CurrencyService($this->db);
        foreach (['USD', 'EUR'] as $code) { $currency->createCurrency($this->currencyFixture($code)); }
        $this->db->statement('UPDATE currencies SET is_default = 1'); $before = $this->db->select('SELECT * FROM currencies ORDER BY code');
        $caught = null;
        try { $currency->getDefaultCurrency(); } catch (ValidationException $error) { $caught = $error; }
        self::assertNotNull($caught, 'Multiple defaults must not pick the first row');
        self::assertSame($before, $this->db->select('SELECT * FROM currencies ORDER BY code'));
    }
    public function testAmbiguousActiveCurrencyFallbackDoesNotPickFirstRow(): void
    {
        $this->singletonSchema(); $currency = new CurrencyService($this->db);
        foreach (['USD', 'EUR'] as $code) { $currency->createCurrency($this->currencyFixture($code)); }
        $caught = null;
        try { $currency->getDefaultCurrency(); } catch (ValidationException $error) { $caught = $error; }
        self::assertNotNull($caught, 'Several active currencies without a default must require explicit selection');
    }
    public function testInstallerCannotCreateSecondActiveBrandOrAnOrphanOrganization(): void
    {
        (new \Coleza\Domain\Installer\DatabaseSetupService())->initializeCoreSchema($this->db);
        $owner = (new \Coleza\Domain\Installer\AdminBootstrapService())->bootstrapAdmin($this->db, new \Coleza\Domain\Installer\AdminSetupDto('owner@example.test', 'OwnerFixture123!', 'Owner', 'Admin'))['id'];
        $this->db->insert('brands', ['name' => 'Existing', 'code' => 'existing']);
        $before = [];
        foreach (['brands', 'organizations', 'organization_members', 'installer_provider_identity', 'system_settings'] as $table) { $before[$table] = $this->db->select("SELECT * FROM {$table}"); }
        $caught = null;
        try { (new \Coleza\Domain\Installer\BrandSetupService())->configureBrand($this->db, new \Coleza\Domain\Installer\BrandSetupDto('Company', 'New', 'support@example.test'), ownerUserId: $owner); }
        catch (ValidationException $error) { $caught = $error; }
        self::assertNotNull($caught, 'Installer must not bypass single-active brand policy');
        foreach ($before as $table => $rows) { self::assertSame($rows, $this->db->select("SELECT * FROM {$table}")); }
    }
    public function testFailedDefaultChangeUsesSavepointAndCallerCanCommitOtherWork(): void
    {
        $this->singletonSchema(); $currency = new CurrencyService($this->db); $currency->createCurrency($this->currencyFixture('USD', true));
        $before = $this->db->select('SELECT * FROM currencies');
        $this->db->statement('CREATE TABLE singleton_marker (id INT PRIMARY KEY)');
        $this->db->statement($this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER reject_default_insert BEFORE INSERT ON currencies FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected default insert'"
            : "CREATE TRIGGER reject_default_insert BEFORE INSERT ON currencies BEGIN SELECT RAISE(ABORT, 'Injected default insert'); END");
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        foreach (['managed', 'raw'] as $i => $mode) {
            if ($mode === 'raw') { $this->db->getPdo()->beginTransaction(); } else { $this->db->beginTransaction(); }
            try {
                $this->db->insert('singleton_marker', ['id' => $i + 1]);
                try { $currency->createCurrency($this->currencyFixture('EUR', true)); self::fail('Injected insert succeeded'); }
                catch (\PDOException $error) { self::assertStringContainsString('Injected default insert', $error->getMessage()); }
                self::assertTrue($this->db->getPdo()->inTransaction());
                self::assertSame($before, $this->db->select('SELECT * FROM currencies'));
                if ($mode === 'raw') { $this->db->getPdo()->commit(); } else { $this->db->commit(); }
            } finally { if ($this->db->getPdo()->inTransaction()) { if ($mode === 'raw') { $this->db->getPdo()->rollBack(); } else { $this->db->rollBack(); } } }
        }
        self::assertSame([['id' => 1], ['id' => 2]], $this->db->select('SELECT * FROM singleton_marker ORDER BY id'));
        $this->assertSingletonNoDdl();
    }
    private function assertSingletonNoDdl(): void
    {
        foreach ($this->db->getQueryLog() as $query) { self::assertDoesNotMatchRegularExpression('/^\s*(CREATE|ALTER|DROP|TRUNCATE)\b/i', $query['query']); }
    }
    public function testSuccessfulRawCallerWritesRollbackBrandCurrencyAndLockRows(): void
    {
        $this->singletonSchema(); $this->db->enableQueryLog(); $this->db->flushQueryLog();
        $this->db->getPdo()->beginTransaction();
        try {
            $brand = new BrandService($this->db); $brand->getActiveBrand(['name' => 'Initial']); $brand->updateActiveBrand(['name' => 'Changed']);
            (new CurrencyService($this->db))->getDefaultCurrency();
            self::assertTrue($this->db->getPdo()->inTransaction()); $this->assertSingletonNoDdl();
        } finally { $this->db->getPdo()->rollBack(); }
        foreach (['brands', 'currencies', 'configuration_write_locks'] as $table) { self::assertSame([], $this->db->select("SELECT * FROM {$table}")); }
    }
    public function testInactiveDefaultIsRejectedAndSingleActiveFallbackDoesNotPromoteItself(): void
    {
        $this->singletonSchema(); $currency = new CurrencyService($this->db);
        $invalid = $this->currencyFixture('USD', true); $invalid['is_active'] = false;
        try { $currency->createCurrency($invalid); self::fail('Inactive default accepted'); }
        catch (ValidationException) { self::assertSame([], $this->db->select('SELECT * FROM currencies')); }
        $currency->createCurrency($this->currencyFixture('EUR')); $before = $this->db->select('SELECT * FROM currencies');
        self::assertSame('EUR', $currency->getDefaultCurrency()->getCode()); self::assertSame($before, $this->db->select('SELECT * FROM currencies'));
        $this->db->statement('UPDATE currencies SET is_default = 1, is_active = 0');
        try { $currency->getDefaultCurrency(); self::fail('Legacy inactive default accepted'); }
        catch (ValidationException) { self::assertSame(0, (int) $this->db->selectOne('SELECT is_active FROM currencies')['is_active']); }
    }
    public function testAmbiguousLegacyDefaultsCannotBeClearedByAnUnrelatedCreate(): void
    {
        $this->singletonSchema(); $currency = new CurrencyService($this->db);
        foreach (['USD', 'EUR'] as $code) { $currency->createCurrency($this->currencyFixture($code)); }
        $this->db->statement('UPDATE currencies SET is_default = 1'); $before = $this->db->select('SELECT * FROM currencies ORDER BY code');
        try { $currency->createCurrency($this->currencyFixture('JPY', true)); self::fail('Ambiguous defaults silently reconciled'); }
        catch (ValidationException) { self::assertSame($before, $this->db->select('SELECT * FROM currencies ORDER BY code')); }
    }
    public function testMissingLockSchemaInCallerTransactionStopsBeforeBusinessWrites(): void
    {
        $this->singletonSchema(); $this->db->statement('DROP TABLE configuration_write_locks');
        $this->db->getPdo()->beginTransaction();
        try {
            try { (new CurrencyService($this->db))->createCurrency($this->currencyFixture('USD', true)); self::fail('Missing lock schema accepted'); }
            catch (\LogicException) { self::assertSame([], $this->db->select('SELECT * FROM currencies')); }
            self::assertTrue($this->db->getPdo()->inTransaction());
        } finally { $this->db->getPdo()->rollBack(); }
    }
    public function testInconsistentLegacyLockIdentityStopsBeforeCallback(): void
    {
        $this->singletonSchema(); $this->db->statement('DROP TABLE configuration_write_locks');
        $this->db->statement('CREATE TABLE configuration_write_locks (id INT PRIMARY KEY, scope VARCHAR(16))');
        $this->db->insert('configuration_write_locks', ['id' => 1, 'scope' => 'currency']); $calls = 0;
        try { (new \Coleza\Foundation\Database\ConfigurationWriteLock($this->db))->run('brand', function () use (&$calls) { $calls++; }); self::fail('Wrong scope accepted'); }
        catch (\LogicException $error) { self::assertStringContainsString('identity is inconsistent', $error->getMessage()); }
        self::assertSame(0, $calls); self::assertSame([['id' => 1, 'scope' => 'currency']], $this->db->select('SELECT * FROM configuration_write_locks'));
    }
    public function testMigrationReplayPreservesConfigurationAndExistingLockRows(): void
    {
        $runner = new \Coleza\Foundation\Database\Migrator($this->db); $path = dirname(__DIR__, 2) . '/database/migrations'; $runner->migrate($path);
        (new BrandService($this->db))->getActiveBrand(['name' => 'İstanbul 🧪']);
        (new CurrencyService($this->db))->createCurrency($this->currencyFixture('EUR', true));
        $before = [];
        foreach (['brands', 'currencies', 'configuration_write_locks', 'migrations'] as $table) { $before[$table] = $this->db->select("SELECT * FROM {$table}"); }
        $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_10_12_000020_configuration_write_locks.php';
        $migration->up($this->db); $migration->up($this->db); self::assertSame([], $runner->migrate($path));
        foreach ($before as $table => $rows) { self::assertSame($rows, $this->db->select("SELECT * FROM {$table}")); }
    }
    public function testRawInstallerCallerCanRollbackOrganizationBrandBindingAndMutex(): void
    {
        (new \Coleza\Domain\Installer\DatabaseSetupService())->initializeCoreSchema($this->db);
        $owner = (new \Coleza\Domain\Installer\AdminBootstrapService())->bootstrapAdmin($this->db, new \Coleza\Domain\Installer\AdminSetupDto('owner@example.test', 'OwnerFixture123!', 'Owner', 'Admin'))['id'];
        $before = [];
        foreach (['brands', 'organizations', 'organization_members', 'installer_provider_identity', 'system_settings', 'configuration_write_locks'] as $table) { $before[$table] = $this->db->select("SELECT * FROM {$table}"); }
        $this->db->getPdo()->beginTransaction();
        try {
            (new \Coleza\Domain\Installer\BrandSetupService())->configureBrand($this->db, new \Coleza\Domain\Installer\BrandSetupDto('Company', 'Provider', 'support@example.test'), ownerUserId: $owner);
            self::assertTrue($this->db->getPdo()->inTransaction());
        } finally { $this->db->getPdo()->rollBack(); }
        foreach ($before as $table => $rows) { self::assertSame($rows, $this->db->select("SELECT * FROM {$table}")); }
    }
    public function testUnexpectedlyEndedManagedCallerCannotRunConfigurationCallback(): void
    {
        $this->singletonSchema(); $this->db->beginTransaction(); $this->db->getPdo()->commit(); $calls = 0;
        try {
            try { (new \Coleza\Foundation\Database\ConfigurationWriteLock($this->db))->run('brand', function () use (&$calls) { $calls++; }); self::fail('Lost caller transaction accepted'); }
            catch (\LogicException $error) { self::assertStringContainsString('ended unexpectedly', $error->getMessage()); }
            self::assertSame(0, $calls); self::assertSame([], $this->db->select('SELECT * FROM configuration_write_locks'));
        } finally { $this->db->rollBack(); }
    }
    public function testLockWithoutPrimaryKeyStopsBeforeWritingConfiguration(): void
    {
        $this->singletonSchema(); $this->db->statement('DROP TABLE configuration_write_locks');
        $this->db->statement('CREATE TABLE configuration_write_locks (id INT, scope VARCHAR(16))');
        try { (new BrandService($this->db))->createBrand('Refused', 'refused'); self::fail('Non-unique lock identity accepted'); }
        catch (\LogicException $error) { self::assertStringContainsString('single id primary key', $error->getMessage()); }
        self::assertSame([], $this->db->select('SELECT * FROM brands'));
    }
    public function testCanonicalLockSchemaRejectsNullIdentity(): void
    {
        $this->singletonSchema();
        try { $this->db->insert('configuration_write_locks', ['id' => null, 'scope' => 'brand']); self::fail('NULL mutex identity accepted'); }
        catch (\PDOException) { self::assertSame([], $this->db->select('SELECT * FROM configuration_write_locks')); }
    }
}
