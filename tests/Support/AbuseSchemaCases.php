<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;

use Coleza\Domain\Abuse\{AbuseCaseService, AbuseCategory, AbuseSeverity};
use Coleza\Foundation\Database\Migrator;

trait AbuseSchemaCases
{
    private function service(): AbuseCaseService { return new AbuseCaseService($this->db); }
    private function createFixture(): \Coleza\Domain\Abuse\AbuseCase
    {
        return $this->service()->createCase(AbuseCategory::SPAM, AbuseSeverity::HIGH,
            'reporter@example.test', 'domain', 'example.test', 'Şikâyet 🧪', 'Evidence preserved');
    }
    private function migration(): \Coleza\Foundation\Database\MigrationInterface
    {
        return require dirname(__DIR__, 2) . '/database/migrations/2026_10_12_000021_abuse_schema.php';
    }
    private function assertNoDdl(): void
    {
        foreach ($this->db->getQueryLog() as $query) {
            self::assertDoesNotMatchRegularExpression('/^\s*(CREATE|ALTER|DROP|TRUNCATE)\b/i', $query['query']);
        }
    }
    public function testProductionMigrationsInstallAbuseTablesWithoutFirstRequest(): void
    {
        (new Migrator($this->db))->migrate(dirname(__DIR__, 2) . '/database/migrations');
        $missing = [];
        foreach (['abuse_cases', 'abuse_case_messages'] as $table) {
            try { self::assertSame([], $this->db->select("SELECT * FROM {$table}")); }
            catch (\PDOException) { $missing[] = $table; }
        }
        self::assertSame([], $missing, 'Abuse schema belongs in production migrations');
    }
    public function testInstalledReadsAndWritesPreserveRawCallerRollbackWithoutDdl(): void
    {
        $this->service()->ensureTables();
        $case = $this->createFixture();
        $before = $this->db->select('SELECT * FROM abuse_cases');
        $this->db->statement('CREATE TABLE abuse_guard_marker (id INT PRIMARY KEY)');
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        $this->db->getPdo()->beginTransaction();
        try {
            $this->db->insert('abuse_guard_marker', ['id' => 1]);
            $created = $this->createFixture();
            $this->service()->addMessage($case->getId(), 'staff', 'Reply 🧪', 12);
            $this->service()->resolveCase($case->getId(), 12, 'Resolved');
            self::assertNotNull($this->service()->getCaseByNumber($created->getCaseNumber()));
            $this->service()->getCasesForResource('domain', 'example.test');
            $this->service()->getMessages($case->getId());
            $this->service()->getOverdueCases();
            $active = $this->db->getPdo()->inTransaction();
            self::assertTrue($active, 'Runtime schema must not commit unrelated caller data');
            $this->assertNoDdl();
        } finally { if ($this->db->getPdo()->inTransaction()) { $this->db->getPdo()->rollBack(); } }
        self::assertTrue($active, 'Runtime schema must not commit unrelated caller data');
        self::assertSame([], $this->db->select('SELECT * FROM abuse_guard_marker'));
        self::assertSame($before, $this->db->select('SELECT * FROM abuse_cases'));
        self::assertSame([], $this->db->select('SELECT * FROM abuse_case_messages'));
    }
    public function testMissingSchemaInsideRawCallerRefusesBeforeDdl(): void
    {
        $this->db->statement('CREATE TABLE abuse_guard_marker (id INT PRIMARY KEY)');
        $this->db->enableQueryLog(); $this->db->getPdo()->beginTransaction();
        try {
            $this->db->insert('abuse_guard_marker', ['id' => 1]);
            try { $this->createFixture(); self::fail('Missing schema was installed inside caller'); }
            catch (\LogicException $error) { self::assertStringContainsString('before starting', $error->getMessage()); }
            self::assertTrue($this->db->getPdo()->inTransaction()); $this->assertNoDdl();
        } finally { if ($this->db->getPdo()->inTransaction()) { $this->db->getPdo()->rollBack(); } }
        self::assertSame([], $this->db->select('SELECT * FROM abuse_guard_marker'));
    }
    public function testPartialExistingSchemaRefusesBeforeCreatingSibling(): void
    {
        $this->db->statement('CREATE TABLE abuse_case_messages (id INT PRIMARY KEY, message TEXT)');
        $this->db->insert('abuse_case_messages', ['id' => 7, 'message' => 'Legacy 🧪']);
        $before = $this->db->select('SELECT * FROM abuse_case_messages');
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        try { $this->service()->ensureTables(); self::fail('Partial schema silently accepted'); }
        catch (\LogicException $error) { self::assertStringContainsString('explicit upgrade', $error->getMessage()); }
        $this->assertNoDdl();
        self::assertSame($before, $this->db->select('SELECT * FROM abuse_case_messages'));
    }
    public function testMigrationReplayPreservesCasesMessagesAndTimestamps(): void
    {
        $this->service()->ensureTables(); $case = $this->createFixture();
        $this->service()->addMessage($case->getId(), 'staff', 'Internal 🧪', 9, true);
        $before = [];
        foreach (['abuse_cases', 'abuse_case_messages'] as $table) { $before[$table] = $this->db->select("SELECT * FROM {$table}"); }
        $this->migration()->up($this->db); $this->migration()->up($this->db);
        foreach ($before as $table => $rows) { self::assertSame($rows, $this->db->select("SELECT * FROM {$table}")); }
    }
    public function testUpgradeRepairsMissingLaterIndexAndPreservesEvidence(): void
    {
        $this->service()->ensureTables(); $this->createFixture();
        $before = $this->db->select('SELECT * FROM abuse_cases');
        $suffix = $this->db->getDriverName() === 'mysql' ? ' ON abuse_cases' : '';
        $this->db->statement('DROP INDEX idx_abuse_status' . $suffix);
        $this->migration()->up($this->db);
        foreach (['idx_abuse_resource' => 'abuse_cases', 'idx_abuse_status' => 'abuse_cases', 'idx_abuse_messages_case' => 'abuse_case_messages'] as $index => $table) {
            $row = $this->db->getDriverName() === 'mysql'
                ? $this->db->selectOne('SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', [$table, $index])
                : $this->db->selectOne("SELECT 1 FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name = ?", [$table, $index]);
            self::assertNotNull($row, 'All expected indexes must be installed');
        }
        self::assertSame($before, $this->db->select('SELECT * FROM abuse_cases'));
    }
    public function testPartialUpgradeDoesNotRecordSuccessAndCanResumeAfterExplicitRepair(): void
    {
        $this->db->statement('CREATE TABLE abuse_case_messages (id INT PRIMARY KEY, message TEXT)');
        $this->db->insert('abuse_case_messages', ['id' => 7, 'message' => 'Preserve legacy evidence']);
        $runner = new Migrator($this->db);
        try { $runner->migrate(dirname(__DIR__, 2) . '/database/migrations'); self::fail('Partial schema migration accepted'); }
        catch (\LogicException $error) { self::assertStringContainsString('explicit upgrade', $error->getMessage()); }
        self::assertCount(20, $runner->getRanMigrations());
        self::assertSame('Preserve legacy evidence', $this->db->selectOne('SELECT message FROM abuse_case_messages WHERE id = 7')['message']);
        // Fixture-only, explicit repair: preserve the legacy row before replacing the incomplete schema.
        $legacy = $this->db->select('SELECT * FROM abuse_case_messages');
        $this->db->statement('DROP TABLE abuse_case_messages');
        $this->service()->ensureTables(); $case = $this->createFixture();
        $this->db->insert('abuse_case_messages', ['id' => 7, 'case_id' => $case->getId(), 'author_type' => 'staff', 'message' => $legacy[0]['message']]);
        self::assertSame(['2026_10_12_000021_abuse_schema', '2026_10_12_000022_fraud_schema', '2026_10_12_000023_operational_mode_schema', '2026_10_13_000024_analytics_schema', '2026_10_13_000025_daily_aggregation_evidence'], $runner->migrate(dirname(__DIR__, 2) . '/database/migrations'));
        self::assertCount(25, $runner->getRanMigrations());
        self::assertSame('Preserve legacy evidence', $this->db->selectOne('SELECT message FROM abuse_case_messages WHERE id = 7')['message']);
    }
    public function testRollbackRequiresExplicitRetentionPlanAndPreservesRecords(): void
    {
        $this->service()->ensureTables(); $this->createFixture();
        $before = $this->db->select('SELECT * FROM abuse_cases');
        try { $this->migration()->down($this->db); self::fail('Destructive rollback accepted'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('explicit maintenance plan', $error->getMessage()); }
        self::assertSame($before, $this->db->select('SELECT * FROM abuse_cases'));
    }
}
