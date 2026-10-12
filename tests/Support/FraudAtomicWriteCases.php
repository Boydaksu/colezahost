<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
use Coleza\Domain\Fraud\Risk\{RiskScoreService, RiskContext, RiskSignal};
use Coleza\Domain\Fraud\Review\RiskReviewService;
use PHPUnit\Framework\Attributes\DataProvider;
trait FraudAtomicWriteCases
{
    private function score(): RiskScoreService
    {
        $rules = [];
        foreach (['first', 'second'] as $code) {
            $rules[] = new class($code) implements \Coleza\Domain\Fraud\Risk\Rules\RiskRuleInterface {
                public function __construct(private string $code) {}
                public function getRuleCode(): string { return $this->code; }
                public function getRuleName(): string { return $this->code; }
                public function getDefaultWeight(): int { return 20; }
                public function evaluate(RiskContext $context): ?RiskSignal { return new RiskSignal($this->code, 20, 'medium', 'Fixture 🧪'); }
            };
        }
        return new RiskScoreService($this->db, $rules);
    }
    private function review(): RiskReviewService { return new RiskReviewService($this->db, $this->score()); }
    private function install(): void { $this->score()->ensureTables(); $this->review()->ensureTables(); }
    private function evaluate(): \Coleza\Domain\Fraud\Risk\RiskEvaluationResult { return $this->score()->evaluate(new RiskContext('order', entityId: 71, email: 'test@example.test')); }
    private function rows(): array
    {
        $rows = []; foreach (['fraud_risk_evaluations', 'fraud_risk_signals', 'fraud_risk_reviews', 'fraud_risk_review_decisions'] as $table) { $rows[$table] = $this->db->select("SELECT * FROM {$table} ORDER BY id"); } return $rows;
    }
    private function failInsert(string $table, bool $second = false): void
    {
        $condition = $second ? "NEW.rule_code = 'second'" : '1 = 1';
        $sql = $this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER fraud_test_fail BEFORE INSERT ON {$table} FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected fraud write failure'; END IF; END"
            : "CREATE TRIGGER fraud_test_fail BEFORE INSERT ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Injected fraud write failure'); END";
        $this->db->statement($sql);
    }
    public static function failureModes(): array { return [['score', 'standalone'], ['score', 'raw'], ['score', 'managed'], ['review', 'standalone'], ['review', 'raw'], ['review', 'managed']]; }
    #[DataProvider('failureModes')]
    public function testFailedChildInsertRollsBackEntireOperationAndPreservesCaller(string $operation, string $mode): void
    {
        $this->install();
        $review = $operation === 'review' ? $this->review()->createReview($this->evaluate()) : null;
        $before = $this->rows(); $this->db->statement('CREATE TABLE fraud_atomic_marker (id INT PRIMARY KEY)');
        $this->failInsert($operation === 'score' ? 'fraud_risk_signals' : 'fraud_risk_review_decisions', $operation === 'score');
        if ($mode === 'raw') { $this->db->getPdo()->beginTransaction(); }
        elseif ($mode === 'managed') { $this->db->beginTransaction(); }
        if ($mode !== 'standalone') { $this->db->insert('fraud_atomic_marker', ['id' => 1]); }
        try {
            try {
                if ($review === null) { $this->evaluate(); } else { $this->review()->approveReview($review->getId(), 42, 'Reviewed 🧪'); }
                self::fail('Injected write did not fail');
            } catch (\PDOException $error) { self::assertStringContainsString('Injected fraud write failure', $error->getMessage()); }
            self::assertSame($before, $this->rows(), 'No parent, first signal or resolved status may survive the failed operation');
            if ($mode === 'standalone') { self::assertFalse($this->db->getPdo()->inTransaction()); }
            else {
                self::assertTrue($this->db->getPdo()->inTransaction());
                if ($mode === 'raw') { $this->db->getPdo()->commit(); } else { $this->db->commit(); }
                self::assertSame([['id' => 1]], $this->db->select('SELECT * FROM fraud_atomic_marker'));
            }
        } finally { if ($this->db->getPdo()->inTransaction()) { if ($mode === 'managed') { $this->db->rollBack(); } else { $this->db->getPdo()->rollBack(); } } }
    }
    public static function callerModes(): array { return [['raw'], ['managed']]; }
    #[DataProvider('callerModes')]
    public function testSuccessfulAggregateWritesRemainInCallerRollback(string $mode): void
    {
        $this->install(); $before = $this->rows();
        if ($mode === 'raw') { $this->db->getPdo()->beginTransaction(); } else { $this->db->beginTransaction(); }
        try {
            $review = $this->review()->createReview($this->evaluate());
            $this->review()->approveReview($review->getId(), 42, 'Reviewed 🧪', ['note' => 'İstanbul']);
            self::assertTrue($this->review()->verifySnapshotIntegrity($review->getId()));
            self::assertTrue($this->db->getPdo()->inTransaction());
        } finally { if ($mode === 'raw') { $this->db->getPdo()->rollBack(); } else { $this->db->rollBack(); } }
        self::assertSame($before, $this->rows());
    }
    public function testDecisionRequiresPersistedOriginalEvaluationWithoutGuessing(): void
    {
        $this->install(); $evaluation = $this->evaluate(); $review = $this->review()->createReview($evaluation);
        $this->db->statement('DELETE FROM fraud_risk_evaluations WHERE id = ?', [$evaluation->getId()]); $before = $this->rows();
        try { (new RiskReviewService($this->db))->approveReview($review->getId(), 42, 'Reviewed'); self::fail('Missing original assessment was guessed'); }
        catch (\LogicException $error) { self::assertStringContainsString('evaluation', $error->getMessage()); }
        self::assertSame($before, $this->rows());
    }
    public function testDuplicateLegacyReviewsRefuseCreateWithoutMutatingRows(): void
    {
        $this->install(); $evaluation = $this->evaluate(); $this->review()->createReview($evaluation);
        $row = $this->db->selectOne('SELECT * FROM fraud_risk_reviews'); unset($row['id']); $this->db->insert('fraud_risk_reviews', $row); $before = $this->rows();
        try { $this->review()->createReview($evaluation); self::fail('Ambiguous reviews accepted'); }
        catch (\LogicException $error) { self::assertStringContainsString('multiple', $error->getMessage()); }
        self::assertSame($before, $this->rows());
    }
    public function testExistingDecisionCannotBeReopenedByClaimEscalationOrResolution(): void
    {
        $this->install(); $review = $this->review()->createReview($this->evaluate()); $this->review()->approveReview($review->getId(), 42, 'First');
        // Simulate an inconsistent legacy/restore state without erasing the existing decision.
        $this->db->statement("UPDATE fraud_risk_reviews SET status = 'pending' WHERE id = ?", [$review->getId()]); $before = $this->rows();
        foreach (['claim', 'escalate', 'reject'] as $action) {
            try {
                match ($action) { 'claim' => $this->review()->claimReview($review->getId(), 43), 'escalate' => $this->review()->escalateReview($review->getId(), 43), 'reject' => $this->review()->rejectReview($review->getId(), 43, 'Second') };
                self::fail('An existing decision was ignored');
            } catch (\LogicException $error) { self::assertStringContainsString('decision', $error->getMessage()); }
        }
        self::assertSame($before, $this->rows());
    }
    public function testInvalidMetadataFailsWithoutChangingReview(): void
    {
        $this->install(); $review = $this->review()->createReview($this->evaluate()); $before = $this->rows();
        try { $this->review()->approveReview($review->getId(), 42, 'Reviewed', ['invalid' => "\xB1"]); self::fail('Invalid JSON metadata accepted'); }
        catch (\JsonException) {}
        self::assertSame($before, $this->rows());
    }
    public function testReviewUsesPersistedIdentityRatherThanCallerSuppliedFields(): void
    {
        $this->install(); $evaluation = $this->evaluate();
        $fake = new \Coleza\Domain\Fraud\Risk\RiskEvaluationResult($evaluation->getId(), 'payment', 999, 999, 999, 0,
            \Coleza\Domain\Fraud\Risk\RiskDecision::ACCEPT, [], [], new \DateTimeImmutable());
        $review = $this->review()->createReview($fake);
        self::assertSame('order', $review->getEntityType()); self::assertSame(71, $review->getEntityId()); self::assertNull($review->getUserId());
        $snapshot = (new RiskReviewService($this->db))->approveReview($review->getId(), 42, 'Reviewed');
        self::assertSame('review', $snapshot->getOriginalDecision());
    }
    public function testDuplicateDecisionReadRefusesToPickLatest(): void
    {
        $this->install(); $review = $this->review()->createReview($this->evaluate()); $this->review()->approveReview($review->getId(), 42, 'First');
        $row = $this->db->selectOne('SELECT * FROM fraud_risk_review_decisions'); unset($row['id']); $this->db->insert('fraud_risk_review_decisions', $row);
        $before = $this->rows();
        try { $this->review()->getDecisionSnapshot($review->getId()); self::fail('Ambiguous decisions accepted'); }
        catch (\LogicException $error) { self::assertStringContainsString('multiple', $error->getMessage()); }
        self::assertSame($before, $this->rows());
    }
    public function testLostManagedCallerRefusesBeforeAnyWriteOrDdl(): void
    {
        $this->db->beginTransaction(); $this->db->getPdo()->rollBack(); $this->db->enableQueryLog();
        try { $this->evaluate(); self::fail('Lost transaction accepted'); }
        catch (\LogicException $error) { self::assertStringContainsString('ended unexpectedly', $error->getMessage()); }
        self::assertSame([], $this->db->getQueryLog()); $this->db->rollBack();
    }
}
