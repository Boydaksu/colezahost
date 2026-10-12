<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class FraudAtomicWriteTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\FraudAtomicWriteCases;
    protected function setUp(): void { parent::setUp(); $this->root->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false); }
    public function testParallelCreateUsesOneReviewForOneEvaluation(): void
    {
        $this->install(); $evaluation = $this->evaluate();
        $results = $this->runParallel('fraudReviewCreate', $evaluation->getId());
        self::assertSame(['ok', 'ok', 'ok', 'ok'], array_column($results, 'status'));
        self::assertCount(1, array_unique(array_column($results, 'review_id')));
        self::assertCount(1, $this->db->select('SELECT * FROM fraud_risk_reviews'));
    }
    public function testParallelOpposingResolutionsProduceOneTerminalDecision(): void
    {
        $this->install(); $review = $this->review()->createReview($this->evaluate());
        $results = $this->runParallel('fraudReviewResolve', $review->getId());
        self::assertCount(1, array_filter($results, fn($row) => $row['status'] === 'ok'));
        self::assertCount(3, array_filter($results, fn($row) => $row['status'] === 'rejected'));
        $decisions = $this->db->select('SELECT * FROM fraud_risk_review_decisions'); self::assertCount(1, $decisions);
        $row = $this->db->selectOne('SELECT * FROM fraud_risk_reviews');
        self::assertSame($decisions[0]['final_decision'], $row['status']);
        self::assertSame((int) $decisions[0]['decided_by_staff_id'], (int) $row['resolved_by']);
        self::assertTrue($this->review()->verifySnapshotIntegrity($review->getId()));
    }
    public function testParallelClaimEscalationAndResolutionCannotReopenDecision(): void
    {
        $this->install(); $review = $this->review()->createReview($this->evaluate());
        $this->runParallel('fraudReviewMixed', $review->getId());
        $decisions = $this->db->select('SELECT * FROM fraud_risk_review_decisions'); self::assertCount(1, $decisions);
        self::assertSame($decisions[0]['final_decision'], $this->db->selectOne('SELECT status FROM fraud_risk_reviews')['status']);
    }
    public function testParallelCreateAndResolveUseConsistentParentLockOrder(): void
    {
        $this->install(); $review = $this->review()->createReview($this->evaluate());
        $results = $this->runParallel('fraudReviewCreateResolve', $review->getId());
        self::assertCount(3, array_filter($results, fn($row) => $row['status'] === 'ok'));
        self::assertCount(1, $this->db->select('SELECT * FROM fraud_risk_reviews')); self::assertCount(1, $this->db->select('SELECT * FROM fraud_risk_review_decisions'));
    }
    private function peer(): \Coleza\Foundation\Database\Connection
    {
        $pdo = new \PDO(getenv('COLEZA_TEST_MARIADB_DSN'), getenv('COLEZA_TEST_MARIADB_USER'), getenv('COLEZA_TEST_MARIADB_PASSWORD'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false]);
        $pdo->exec('USE `' . $this->database . '`'); return new \Coleza\Foundation\Database\Connection($pdo);
    }
    public function testOldCallerSnapshotCannotOverwritePeerCommittedDecision(): void
    {
        $this->install(); $review = $this->review()->createReview($this->evaluate()); $peer = $this->peer();
        $this->db->getPdo()->beginTransaction();
        try {
            self::assertSame('pending', $this->db->selectOne('SELECT status FROM fraud_risk_reviews')['status']);
            (new \Coleza\Domain\Fraud\Review\RiskReviewService($peer))->approveReview($review->getId(), 42, 'Peer');
            try { $this->review()->rejectReview($review->getId(), 43, 'Stale'); self::fail('Stale decision overwrote current state'); }
            catch (\Coleza\Foundation\Exceptions\ValidationException) {}
            self::assertTrue($this->db->getPdo()->inTransaction());
        } finally { $this->db->getPdo()->rollBack(); }
        self::assertCount(1, $this->db->select('SELECT * FROM fraud_risk_review_decisions')); self::assertSame('approved', $this->db->selectOne('SELECT status FROM fraud_risk_reviews')['status']);
    }
    public function testNontransactionalStorageRefusesBeforeWriting(): void
    {
        $this->install(); $this->db->statement('ALTER TABLE fraud_risk_signals ENGINE=MyISAM'); $before = $this->rows();
        try { $this->evaluate(); self::fail('Nontransactional aggregate accepted'); }
        catch (\LogicException $error) { self::assertStringContainsString('InnoDB', $error->getMessage()); }
        self::assertSame($before, $this->rows());
    }
}
