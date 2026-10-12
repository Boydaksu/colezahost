<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
use Coleza\Domain\Fraud\Lists\{FraudListService, FraudListType, FraudListEntryType};
use Coleza\Domain\Fraud\Risk\{RiskScoreService, RiskContext};
use Coleza\Domain\Fraud\Review\RiskReviewService;
use Coleza\Domain\Fraud\Velocity\VelocityTrackerService;
use Coleza\Foundation\Database\Migrator;
use PHPUnit\Framework\Attributes\DataProvider;

trait FraudSchemaCases
{
    private function tables(): array { return ['fraud_risk_evaluations', 'fraud_risk_signals', 'fraud_risk_reviews', 'fraud_risk_review_decisions', 'fraud_list_entries', 'fraud_velocity_events']; }
    private function score(): RiskScoreService { return new RiskScoreService($this->db); }
    private function review(): RiskReviewService { return new RiskReviewService($this->db, $this->score()); }
    private function service(string $name): object
    {
        return match ($name) { 'score' => $this->score(), 'review' => $this->review(), 'list' => new FraudListService($this->db), 'velocity' => new VelocityTrackerService($this->db) };
    }
    private function context(): RiskContext { return new RiskContext('order', entityId: 71, userId: 12, email: 'test@mailinator.com', customAttributes: ['note' => 'İstanbul 🧪']); }
    private function install(): void { foreach (['score', 'review', 'list', 'velocity'] as $name) { $this->service($name)->ensureTables(); } }
    private function migration(): \Coleza\Foundation\Database\MigrationInterface { return require dirname(__DIR__, 2) . '/database/migrations/2026_10_12_000022_fraud_schema.php'; }
    private function rows(): array { $rows = []; foreach ($this->tables() as $table) { $rows[$table] = $this->db->select("SELECT * FROM {$table} ORDER BY id"); } return $rows; }
    private function exercise(string $name): void
    {
        if ($name === 'score') {
            $evaluation = $this->score()->evaluate($this->context());
            self::assertNotNull($this->score()->getEvaluation($evaluation->getId()));
        } elseif ($name === 'review') {
            $evaluation = $this->score()->evaluate($this->context());
            $review = $this->review()->createReview($evaluation);
            $this->review()->claimReview($review->getId(), 42);
            $this->review()->approveReview($review->getId(), 42, 'Manual reason 🧪', ['note' => 'İstanbul']);
            self::assertNotNull($this->review()->getDecisionSnapshot($review->getId()));
        } elseif ($name === 'list') {
            $entry = $this->service('list')->addEntry(FraudListType::WATCH, FraudListEntryType::EMAIL, 'test@example.test', 'Reason 🧪', 42);
            self::assertNotNull($this->service('list')->getEntry($entry->getId()));
            self::assertSame(FraudListType::WATCH, $this->service('list')->checkValue(FraudListEntryType::EMAIL, 'test@example.test'));
        } else {
            $this->service('velocity')->recordEvent('order_attempt', 'ip', '192.0.2.1', ['note' => 'İstanbul 🧪']);
            self::assertSame(1, $this->service('velocity')->getIpOrderVelocity('192.0.2.1'));
        }
    }
    public static function serviceNames(): array { return [['score'], ['review'], ['list'], ['velocity']]; }
    public static function callerCases(): array
    {
        $cases = []; foreach (self::serviceNames() as [$name]) { foreach ([false, true] as $managed) { $cases[$name . ($managed ? '-managed' : '-raw')] = [$name, $managed]; } } return $cases;
    }
    public static function partialTables(): array
    {
        return array_map(fn ($name) => [$name], ['fraud_risk_evaluations', 'fraud_risk_signals', 'fraud_risk_reviews', 'fraud_risk_review_decisions', 'fraud_list_entries', 'fraud_velocity_events']);
    }
    private function assertNoDdl(): void { foreach ($this->db->getQueryLog() as $query) { self::assertDoesNotMatchRegularExpression('/^\s*(CREATE|ALTER|DROP|TRUNCATE)\b/i', $query['query']); } }
    public function testProductionMigrationsInstallAllFraudTables(): void
    {
        (new Migrator($this->db))->migrate(dirname(__DIR__, 2) . '/database/migrations');
        $missing = []; foreach ($this->tables() as $table) { try { self::assertSame([], $this->db->select("SELECT * FROM {$table}")); } catch (\PDOException) { $missing[] = $table; } }
        self::assertSame([], $missing, 'Fraud schema must be installed before application requests');
    }
    #[DataProvider('callerCases')]
    public function testInstalledOperationsPreserveCallerRollbackWithoutDdl(string $name, bool $managed): void
    {
        $this->install(); $before = $this->rows();
        $this->db->statement('CREATE TABLE fraud_guard_marker (id INT PRIMARY KEY)');
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        if ($managed) { $this->db->beginTransaction(); } else { $this->db->getPdo()->beginTransaction(); }
        try {
            $this->db->insert('fraud_guard_marker', ['id' => 1]); $this->exercise($name);
            self::assertTrue($this->db->getPdo()->inTransaction(), 'Fraud schema must not commit caller data'); $this->assertNoDdl();
        } finally {
            if ($this->db->getPdo()->inTransaction()) { if ($managed) { $this->db->rollBack(); } else { $this->db->getPdo()->rollBack(); } }
        }
        self::assertSame([], $this->db->select('SELECT * FROM fraud_guard_marker')); self::assertSame($before, $this->rows());
    }
    #[DataProvider('serviceNames')]
    public function testMissingSchemaInsideCallerRefusesBeforeDdl(string $name): void
    {
        $this->db->statement('CREATE TABLE fraud_guard_marker (id INT PRIMARY KEY)');
        $this->db->enableQueryLog(); $this->db->getPdo()->beginTransaction();
        try {
            $this->db->insert('fraud_guard_marker', ['id' => 1]);
            try { $this->service($name)->ensureTables(); self::fail('Missing schema installed inside caller'); }
            catch (\LogicException $error) { self::assertStringContainsString('before starting', $error->getMessage()); }
            self::assertTrue($this->db->getPdo()->inTransaction()); $this->assertNoDdl();
        } finally { if ($this->db->getPdo()->inTransaction()) { $this->db->getPdo()->rollBack(); } }
        self::assertSame([], $this->db->select('SELECT * FROM fraud_guard_marker'));
    }
    #[DataProvider('partialTables')]
    public function testMigrationPreflightsAllPartialTablesBeforeAnySiblingDdl(string $table): void
    {
        $this->db->statement("CREATE TABLE {$table} (id INT PRIMARY KEY, legacy_note TEXT)");
        $this->db->insert($table, ['id' => 7, 'legacy_note' => 'Preserve 🧪']);
        $before = $this->db->select("SELECT * FROM {$table}"); $this->db->enableQueryLog(); $this->db->flushQueryLog();
        try { $this->migration()->up($this->db); self::fail('Partial schema accepted'); }
        catch (\LogicException $error) { self::assertStringContainsString('explicit upgrade', $error->getMessage()); }
        $this->assertNoDdl(); self::assertSame($before, $this->db->select("SELECT * FROM {$table}"));
    }
    public function testReplayPreservesAllBusinessRecordsAndSnapshots(): void
    {
        $this->install(); foreach (['review', 'list', 'velocity'] as $name) { $this->exercise($name); }
        $before = $this->rows(); self::assertNotEmpty($before['fraud_risk_signals']); self::assertNotEmpty($before['fraud_risk_review_decisions']);
        $this->migration()->up($this->db); $this->migration()->up($this->db); self::assertSame($before, $this->rows());
    }
    public function testUpgradeCompletesEveryMissingIndexAfterExistingFirstIndex(): void
    {
        $this->install(); $this->exercise('review'); $before = $this->rows();
        foreach (['idx_fraud_eval_user' => 'fraud_risk_evaluations', 'idx_fraud_reviews_status' => 'fraud_risk_reviews'] as $index => $table) {
            $this->db->statement("DROP INDEX {$index}" . ($this->db->getDriverName() === 'mysql' ? " ON {$table}" : ''));
        }
        $this->migration()->up($this->db);
        foreach (['idx_fraud_eval_entity' => 'fraud_risk_evaluations', 'idx_fraud_eval_user' => 'fraud_risk_evaluations', 'idx_fraud_signals_eval' => 'fraud_risk_signals', 'idx_fraud_reviews_eval' => 'fraud_risk_reviews', 'idx_fraud_reviews_status' => 'fraud_risk_reviews', 'idx_fraud_decisions_review' => 'fraud_risk_review_decisions', 'idx_fraud_list_lookup' => 'fraud_list_entries', 'idx_fraud_velocity_lookup' => 'fraud_velocity_events'] as $index => $table) {
            $row = $this->db->getDriverName() === 'mysql'
                ? $this->db->selectOne('SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', [$table, $index])
                : $this->db->selectOne("SELECT 1 FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name = ?", [$table, $index]);
            self::assertNotNull($row, $index);
        }
        self::assertSame($before, $this->rows());
    }
    public function testFailedMigrationHistoryPreservedAndResumesAfterExplicitFixtureRepair(): void
    {
        $this->db->statement('CREATE TABLE fraud_velocity_events (id INT PRIMARY KEY, legacy_note TEXT)');
        $this->db->insert('fraud_velocity_events', ['id' => 7, 'legacy_note' => 'Preserve 🧪']);
        $runner = new Migrator($this->db);
        try { $runner->migrate(dirname(__DIR__, 2) . '/database/migrations'); self::fail('Partial upgrade accepted'); }
        catch (\LogicException $error) { self::assertStringContainsString('explicit upgrade', $error->getMessage()); }
        self::assertCount(21, $runner->getRanMigrations());
        self::assertSame('Preserve 🧪', $this->db->selectOne('SELECT legacy_note FROM fraud_velocity_events')['legacy_note']);
        // Explicit test fixture repair; production must supply its own reviewed transformation.
        $this->db->statement('ALTER TABLE fraud_velocity_events RENAME TO fixture_archived_velocity');
        self::assertSame(['2026_10_12_000022_fraud_schema'], $runner->migrate(dirname(__DIR__, 2) . '/database/migrations'));
        self::assertCount(22, $runner->getRanMigrations()); self::assertSame([], $this->db->select('SELECT * FROM fraud_velocity_events'));
        self::assertSame('Preserve 🧪', $this->db->selectOne('SELECT legacy_note FROM fixture_archived_velocity')['legacy_note']);
    }
    public function testDownRefusesDestructionAndPreservesBusinessEvidence(): void
    {
        $this->install(); $this->exercise('review'); $before = $this->rows();
        try { $this->migration()->down($this->db); self::fail('Destructive rollback accepted'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('explicit maintenance plan', $error->getMessage()); }
        self::assertSame($before, $this->rows());
    }
}
