<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;

use Coleza\Domain\Module\ModuleLifecycleService;
use Coleza\Domain\Module\ModuleManifest;
use Coleza\Domain\Updater\ModuleCompatibilityChecker;

trait ModuleSchemaSafetyCases
{
    private function moduleService(): ModuleLifecycleService { return new ModuleLifecycleService($this->db); }
    private function seedModule(): void
    {
        $this->moduleService()->ensureTable();
        $this->moduleService()->install(new ModuleManifest('original', 'Ödeme İstanbul', '1.0.0', 'integration', '1.0.0', ['original.read']));
        $this->moduleService()->enable('original');
    }
    private function assertModuleReadOnlyLog(): void
    {
        foreach ($this->db->getQueryLog() as $query) {
            self::assertMatchesRegularExpression('/^\s*SELECT\b/i', $query['query']);
        }
    }
    public function testCompatibilityReadPreservesRawCallerRollback(): void
    {
        $this->seedModule();
        $before = $this->db->select('SELECT * FROM installed_modules');
        $this->db->statement('CREATE TABLE module_marker (id INT PRIMARY KEY)');
        $this->db->getPdo()->beginTransaction();
        try {
            $this->db->insert('module_marker', ['id' => 1]);
            $this->db->enableQueryLog(); $this->db->flushQueryLog();
            self::assertTrue((new ModuleCompatibilityChecker($this->db))->checkCompatibility('1.1.0')['compatible']);
            self::assertTrue($this->db->getPdo()->inTransaction(), 'A read must never commit the caller');
            $this->assertModuleReadOnlyLog();
        } finally { if ($this->db->getPdo()->inTransaction()) { $this->db->getPdo()->rollBack(); } }
        self::assertSame([], $this->db->select('SELECT * FROM module_marker'));
        self::assertSame($before, $this->db->select('SELECT * FROM installed_modules'));
    }
    public function testLifecycleWritePreservesManagedCallerRollback(): void
    {
        $this->seedModule(); $before = $this->db->select('SELECT * FROM installed_modules');
        $this->db->beginTransaction();
        try {
            $this->moduleService()->disable('original');
            self::assertTrue($this->db->getPdo()->inTransaction());
            self::assertFalse($this->moduleService()->isEnabled('original'));
            self::assertSame([], $this->moduleService()->getModulesWithCapability('original.read'));
        } finally { if ($this->db->getPdo()->inTransaction()) { $this->db->rollBack(); } }
        self::assertSame($before, $this->db->select('SELECT * FROM installed_modules'));
    }
    public function testCompatibilityDoesNotInventEmptyModuleInventory(): void
    {
        $this->db->enableQueryLog();
        try { (new ModuleCompatibilityChecker($this->db))->checkCompatibility('1.1.0'); self::fail('Missing module table reported compatible'); }
        catch (\LogicException $error) { self::assertStringContainsString('schema', $error->getMessage()); }
        $this->assertModuleReadOnlyLog();
    }
    public function testMissingModuleSchemaInsideRawCallerRefusesBeforeDdl(): void
    {
        $this->db->statement('CREATE TABLE module_marker (id INT PRIMARY KEY)');
        $this->db->getPdo()->beginTransaction();
        try {
            $this->db->insert('module_marker', ['id' => 1]);
            $this->db->enableQueryLog(); $this->db->flushQueryLog();
            try { $this->moduleService()->isEnabled('original'); self::fail('Missing schema silently installed'); }
            catch (\LogicException $error) { self::assertStringContainsString('schema', $error->getMessage()); }
            self::assertTrue($this->db->getPdo()->inTransaction()); $this->assertModuleReadOnlyLog();
        } finally { if ($this->db->getPdo()->inTransaction()) { $this->db->getPdo()->rollBack(); } }
        self::assertSame([], $this->db->select('SELECT * FROM module_marker'));
    }
    public function testPartialInventoryRefusesReadWithoutAlteringHistoricalData(): void
    {
        $this->db->statement('CREATE TABLE installed_modules (id INT PRIMARY KEY, module_id VARCHAR(100), manifest TEXT)');
        $this->db->insert('installed_modules', ['id' => 7, 'module_id' => 'old', 'manifest' => '{"preserved":"İstanbul"}']);
        $before = $this->db->select('SELECT * FROM installed_modules');
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        try { (new ModuleCompatibilityChecker($this->db))->checkCompatibility('1.1.0'); self::fail('Partial inventory accepted'); }
        catch (\LogicException $error) { self::assertStringContainsString('explicit upgrade required', $error->getMessage()); }
        $this->assertModuleReadOnlyLog();
        try { $this->moduleService()->ensureTable(true); self::fail('Partial inventory automatically repaired'); }
        catch (\LogicException) { self::assertSame($before, $this->db->select('SELECT * FROM installed_modules')); }
    }
    public function testInstallInRawCallerRollsBackAndPreservesOriginalModule(): void
    {
        $this->seedModule(); $before = $this->db->select('SELECT * FROM installed_modules');
        $this->db->getPdo()->beginTransaction();
        try {
            $this->moduleService()->install(new ModuleManifest('new', 'Yeni', '1.0.0', 'integration', '1.0.0'));
            $this->moduleService()->enable('new');
            self::assertTrue($this->moduleService()->isEnabled('new'));
            self::assertTrue($this->db->getPdo()->inTransaction());
        } finally { if ($this->db->getPdo()->inTransaction()) { $this->db->getPdo()->rollBack(); } }
        self::assertSame($before, $this->db->select('SELECT * FROM installed_modules'));
    }
    public function testSchemaReplayPreservesDisabledModulesManifestAndTimestamps(): void
    {
        $this->seedModule(); $this->moduleService()->disable('original');
        $this->db->update('installed_modules', ['installed_at' => '2020-01-02 03:04:05', 'updated_at' => '2020-02-03 04:05:06'], 'module_id = :id', ['id' => 'original']);
        $before = $this->db->select('SELECT * FROM installed_modules');
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        $this->moduleService()->ensureTable(true); $this->moduleService()->ensureTable(true);
        self::assertFalse($this->moduleService()->isEnabled('original'));
        self::assertSame([], $this->moduleService()->getModulesWithCapability('original.read'));
        self::assertSame(0, (new ModuleCompatibilityChecker($this->db))->checkCompatibility('1.1.0')['total_checked']);
        $this->assertModuleReadOnlyLog();
        self::assertSame($before, $this->db->select('SELECT * FROM installed_modules'));
    }
    public function testProductionMigrationInstallsCanonicalInventoryAndReplaysWithoutChangingIt(): void
    {
        $runner = new \Coleza\Foundation\Database\Migrator($this->db);
        $runner->migrate(dirname(__DIR__, 2) . '/database/migrations');
        $this->seedModule(); $before = $this->db->select('SELECT * FROM installed_modules');
        self::assertSame(1, (new ModuleCompatibilityChecker($this->db))->checkCompatibility('1.1.0')['total_checked']);
        $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_10_10_000006_core_domain_schemas.php';
        $migration->up($this->db);
        self::assertSame($before, $this->db->select('SELECT * FROM installed_modules'));
        self::assertSame([], $runner->migrate(dirname(__DIR__, 2) . '/database/migrations'));
    }
    public function testDatabaseRejectsDuplicateModuleIdentity(): void
    {
        $this->seedModule(); $row = $this->db->selectOne('SELECT * FROM installed_modules'); unset($row['id']);
        try { $this->db->insert('installed_modules', $row); self::fail('Duplicate module identity accepted'); }
        catch (\PDOException) { self::assertCount(1, $this->db->select('SELECT * FROM installed_modules')); }
    }
}
