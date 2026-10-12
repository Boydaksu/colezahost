<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
use Coleza\Domain\OperationalMode\{OperationalMode, OperationalModeManager};
use Coleza\Foundation\Database\Migrator;
use PHPUnit\Framework\Attributes\DataProvider;
trait OperationalModePersistenceCases
{
    private function manager(): OperationalModeManager { return new OperationalModeManager($this->db); }
    private function install(): void { (new \Coleza\Domain\Installer\DatabaseSetupService())->initializeCoreSchema($this->db); OperationalModeManager::ensureSchema($this->db); }
    private function rows(): array { return $this->db->select('SELECT * FROM system_settings ORDER BY id'); }
    public function testProductionSchemaSupportsFirstOperationalTransition(): void
    {
        (new Migrator($this->db))->migrate(dirname(__DIR__, 2) . '/database/migrations');
        $this->manager()->transitionTo(OperationalMode::READ_ONLY, 'Migration 🧪', ['192.0.2.1']);
        $status = $this->manager()->getModeStatus(); self::assertSame('read_only', $status['mode']); self::assertSame('Migration 🧪', $status['reason']); self::assertSame(['192.0.2.1'], $status['whitelist_ips']);
    }
    public function testConstructAndReadInstalledSchemaPreserveRawCallerWithoutDdl(): void
    {
        $this->install(); $this->db->statement('CREATE TABLE mode_marker (id INT PRIMARY KEY)');
        $this->db->enableQueryLog(); $this->db->getPdo()->beginTransaction();
        try {
            $this->db->insert('mode_marker', ['id' => 1]); self::assertSame(OperationalMode::NORMAL, $this->manager()->getCurrentMode());
            self::assertTrue($this->db->getPdo()->inTransaction(), 'Mode construction must not commit caller data');
            foreach ($this->db->getQueryLog() as $query) { self::assertDoesNotMatchRegularExpression('/^\s*(CREATE|ALTER|DROP|TRUNCATE)\b/i', $query['query']); }
        } finally { if ($this->db->getPdo()->inTransaction()) { $this->db->getPdo()->rollBack(); } }
        self::assertSame([], $this->db->select('SELECT * FROM mode_marker'));
    }
    public static function callerModes(): array { return [['standalone'], ['raw'], ['managed']]; }
    #[DataProvider('callerModes')]
    public function testFailedLaterSettingPreservesWholeModeAndCaller(string $mode): void
    {
        // Use the older mode shape as well; its created_at column is not a canonical dependency.
        $this->manager()->transitionTo(OperationalMode::SAFE, 'Original', ['192.0.2.1']); $before = $this->rows();
        $this->db->statement('CREATE TABLE mode_marker (id INT PRIMARY KEY)');
        $this->db->statement($this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER mode_fail BEFORE UPDATE ON system_settings FOR EACH ROW BEGIN IF NEW.setting_key = 'system.maintenance_whitelist_ips' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected mode failure'; END IF; END"
            : "CREATE TRIGGER mode_fail BEFORE UPDATE ON system_settings WHEN NEW.setting_key = 'system.maintenance_whitelist_ips' BEGIN SELECT RAISE(ABORT, 'Injected mode failure'); END");
        if ($mode === 'raw') { $this->db->getPdo()->beginTransaction(); } elseif ($mode === 'managed') { $this->db->beginTransaction(); }
        if ($mode !== 'standalone') { $this->db->insert('mode_marker', ['id' => 1]); }
        try {
            try { $this->manager()->transitionTo(OperationalMode::RECOVERY, 'Changed', ['192.0.2.2']); self::fail('Injected mode failure accepted'); }
            catch (\PDOException $error) { self::assertStringContainsString('Injected mode failure', $error->getMessage()); }
            self::assertSame($before, $this->rows(), 'Mode, reason, date and whitelist must roll back together');
            if ($mode !== 'standalone') {
                self::assertTrue($this->db->getPdo()->inTransaction());
                if ($mode === 'raw') { $this->db->getPdo()->commit(); } else { $this->db->commit(); }
                self::assertSame([['id' => 1]], $this->db->select('SELECT * FROM mode_marker'));
            } else { self::assertFalse($this->db->getPdo()->inTransaction()); }
        } finally { if ($this->db->getPdo()->inTransaction()) { if ($mode === 'managed') { $this->db->rollBack(); } else { $this->db->getPdo()->rollBack(); } } }
    }
    public function testTransitionInsideRawCallerRemainsRollbackable(): void
    {
        $this->manager(); $before = $this->rows(); $this->db->getPdo()->beginTransaction();
        try { $this->manager()->transitionTo(OperationalMode::READ_ONLY, 'Temporary'); self::assertTrue($this->db->getPdo()->inTransaction()); }
        finally { if ($this->db->getPdo()->inTransaction()) { $this->db->getPdo()->rollBack(); } }
        self::assertSame($before, $this->rows());
    }
    public function testMissingSchemaInCallerRefusesBeforeDdl(): void
    {
        $this->db->getPdo()->beginTransaction(); $this->db->enableQueryLog();
        try {
            try { $this->manager(); self::fail('Missing schema installed inside caller'); }
            catch (\LogicException $error) { self::assertStringContainsString('before starting', $error->getMessage()); }
            self::assertTrue($this->db->getPdo()->inTransaction());
        } finally { if ($this->db->getPdo()->inTransaction()) { $this->db->getPdo()->rollBack(); } }
    }
    public function testUnknownStoredModeRefusesInsteadOfOpeningNormalAccess(): void
    {
        $this->manager()->transitionTo(OperationalMode::RECOVERY, 'Original');
        $this->db->statement("UPDATE system_settings SET setting_value = 'unknown-mode' WHERE setting_key = 'system.operational_mode'");
        $this->expectException(\LogicException::class); $this->manager()->getCurrentMode();
    }
    public function testPartialStoredModeRefusesInsteadOfMixingDefaults(): void
    {
        $this->manager()->transitionTo(OperationalMode::RECOVERY, 'Original');
        $this->db->statement("DELETE FROM system_settings WHERE setting_key = 'system.operational_mode_reason'");
        $this->expectException(\LogicException::class); $this->manager()->getModeStatus();
    }
    public function testLegacyModeTableReplayPreservesDatesAndUnrelatedValues(): void
    {
        $this->db->statement('CREATE TABLE system_settings (id ' . \Coleza\Foundation\Database\PdoSchema::autoIncrement($this->db->getPdo()) . ", setting_key VARCHAR(100) NOT NULL UNIQUE, setting_value TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL)" . ($this->db->getDriverName() === 'mysql' ? ' ENGINE=InnoDB' : ''));
        $this->db->insert('system_settings', ['setting_key' => 'other.original', 'setting_value' => 'İstanbul 🧪', 'created_at' => '2020-01-01 01:00:00']);
        $this->manager()->transitionTo(OperationalMode::SAFE, 'Legacy'); $before = $this->rows();
        $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_10_12_000023_operational_mode_schema.php';
        $migration->up($this->db); $migration->up($this->db); self::assertSame($before, $this->rows());
        try { $migration->down($this->db); self::fail('Shared settings dropped'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('explicit maintenance plan', $error->getMessage()); }
        self::assertSame($before, $this->rows());
    }
    public function testNonuniqueSettingKeysRefuseBeforeWrites(): void
    {
        $this->db->statement('CREATE TABLE system_settings (id INT PRIMARY KEY, setting_key VARCHAR(100), setting_value TEXT, updated_at TIMESTAMP NULL)');
        $this->db->insert('system_settings', ['id' => 7, 'setting_key' => 'unrelated', 'setting_value' => 'Preserve']); $before = $this->rows();
        try { $this->manager()->transitionTo(OperationalMode::SAFE); self::fail('Missing uniqueness accepted'); }
        catch (\LogicException $error) { self::assertStringContainsString('unique setting key', $error->getMessage()); }
        self::assertSame($before, $this->rows());
    }
    public function testFileBackedCallerRefusesBeforeDbOrFileChanges(): void
    {
        $path = sys_get_temp_dir() . '/colezahost_mode_test_' . bin2hex(random_bytes(8));
        $manager = new OperationalModeManager($this->db, $path); $manager->transitionTo(OperationalMode::SAFE, 'Original');
        $before = $this->rows(); $this->db->getPdo()->beginTransaction();
        try {
            try { $manager->transitionTo(OperationalMode::NORMAL); self::fail('File transition joined caller'); }
            catch (\LogicException $error) { self::assertStringContainsString('own transaction', $error->getMessage()); }
            self::assertSame($before, $this->rows()); self::assertSame('safe', file_get_contents($path)); self::assertTrue($this->db->getPdo()->inTransaction());
        } finally { $this->db->getPdo()->rollBack(); unlink($path); }
    }
    public function testFilePreflightFailurePreservesPersistedMode(): void
    {
        $this->manager()->transitionTo(OperationalMode::SAFE, 'Original'); $before = $this->rows();
        $manager = new OperationalModeManager($this->db, sys_get_temp_dir());
        try { $manager->transitionTo(OperationalMode::NORMAL); self::fail('Directory accepted as cache'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('cache path', $error->getMessage()); }
        self::assertSame($before, $this->rows());
    }
    public function testFilePublicationFailureAfterPreflightRollsBackAllSettings(): void
    {
        $path = sys_get_temp_dir() . '/colezahost_mode_test_' . bin2hex(random_bytes(8));
        $this->manager()->transitionTo(OperationalMode::NORMAL, 'Original'); $before = $this->rows();
        $manager = new OperationalModeManager($this->db, $path);
        ModeFileFaultStatement::$insertCount = 0;
        $this->db->getPdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [ModeFileFaultStatement::class, [$path]]);
        try {
            try { $manager->transitionTo(OperationalMode::RECOVERY, 'Changed'); self::fail('Late filesystem failure accepted'); }
            catch (\RuntimeException $error) { self::assertStringContainsString('publication failed', $error->getMessage()); }
            self::assertSame($before, $this->rows()); self::assertFalse($this->db->getPdo()->inTransaction());
            self::assertSame([], glob($path . '.*.tmp'));
        } finally { $this->db->getPdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\PDOStatement::class]); if (is_dir($path)) { rmdir($path); } }
    }
    public function testMismatchedFileCannotOpenPersistedReadOnlyMode(): void
    {
        $path = sys_get_temp_dir() . '/colezahost_mode_test_' . bin2hex(random_bytes(8));
        $manager = new OperationalModeManager($this->db, $path); $manager->transitionTo(OperationalMode::READ_ONLY, 'Original');
        file_put_contents($path, 'normal');
        try {
            try { $manager->assertActionPermitted(isWriteOperation: true); self::fail('Stale permissive cache allowed writes'); }
            catch (\LogicException $error) { self::assertStringContainsString('does not match', $error->getMessage()); }
        } finally { unlink($path); }
    }
    public function testPartialTableSchemaRefusesBeforeDdl(): void
    {
        $this->db->statement('CREATE TABLE system_settings (id INT PRIMARY KEY, setting_key VARCHAR(100), setting_value TEXT)');
        $this->db->insert('system_settings', ['id' => 7, 'setting_key' => 'unrelated', 'setting_value' => 'Preserve']);
        $before = $this->rows(); $this->db->enableQueryLog();
        try { $this->manager(); self::fail('Partial table accepted'); }
        catch (\LogicException $error) { self::assertStringContainsString('explicit upgrade', $error->getMessage()); }
        foreach ($this->db->getQueryLog() as $query) { self::assertDoesNotMatchRegularExpression('/^\s*(CREATE|ALTER|DROP|TRUNCATE)\b/i', $query['query']); }
        self::assertSame($before, $this->rows());
    }
}

final class ModeFileFaultStatement extends \PDOStatement
{
    public static int $insertCount = 0;
    protected function __construct(private string $path) {}
    public function execute(?array $params = null): bool
    {
        $result = parent::execute($params);
        // Simulate a path changing after preflight, immediately before cache publication.
        if (str_starts_with($this->queryString, 'INSERT INTO system_settings') && ++self::$insertCount === 4) { mkdir($this->path); }
        return $result;
    }
}
