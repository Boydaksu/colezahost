<?php

declare(strict_types=1);

namespace Tests\Unit\Fraud;

use Coleza\Domain\Fraud\Review\RiskReviewCase;
use Coleza\Domain\Fraud\Review\RiskReviewDecisionSnapshot;
use Coleza\Domain\Fraud\Review\RiskReviewService;
use Coleza\Domain\Fraud\Review\RiskReviewStatus;
use Coleza\Domain\Fraud\Risk\RiskContext;
use Coleza\Domain\Fraud\Risk\RiskDecision;
use Coleza\Domain\Fraud\Risk\RiskEvaluationResult;
use Coleza\Domain\Fraud\Risk\RiskScoreService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class ManualRiskReviewTest extends TestCase
{
    private Connection $db;
    private RiskScoreService $scoreService;
    private RiskReviewService $reviewService;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->scoreService = new RiskScoreService($this->db);
        $this->scoreService->ensureTables();

        $this->reviewService = new RiskReviewService($this->db, $this->scoreService);
        $this->reviewService->ensureTables();
    }

    private function createSampleEvaluation(int $score = 50, RiskDecision $decision = RiskDecision::REVIEW): RiskEvaluationResult
    {
        $context = new RiskContext(
            entityType: 'order',
            entityId: 1001,
            userId: 55,
            organizationId: 3,
            email: 'review_user@domain.com',
            orderAmount: 450.0
        );

        return $this->scoreService->evaluate($context, reviewThreshold: 30, rejectThreshold: 70);
    }

    public function testCreateReviewCaseFromEvaluation(): void
    {
        $eval = $this->createSampleEvaluation();
        $review = $this->reviewService->createReview($eval, priority: 'high');

        $this->assertNotNull($review->getId());
        $this->assertSame($eval->getId(), $review->getEvaluationId());
        $this->assertSame('order', $review->getEntityType());
        $this->assertSame(1001, $review->getEntityId());
        $this->assertSame(55, $review->getUserId());
        $this->assertSame(3, $review->getOrganizationId());
        $this->assertSame(RiskReviewStatus::PENDING, $review->getStatus());
        $this->assertSame('high', $review->getPriority());
        $this->assertTrue($review->isPending());
        $this->assertFalse($review->isResolved());

        // Idempotency: creating again returns existing review
        $again = $this->reviewService->createReview($eval);
        $this->assertSame($review->getId(), $again->getId());
    }

    public function testClaimAndAssignReviewCase(): void
    {
        $eval = $this->createSampleEvaluation();
        $review = $this->reviewService->createReview($eval);

        $claimed = $this->reviewService->claimReview($review->getId(), staffId: 88);

        $this->assertSame(RiskReviewStatus::IN_REVIEW, $claimed->getStatus());
        $this->assertSame(88, $claimed->getAssignedStaffId());
        $this->assertTrue($claimed->isInReview());
    }

    public function testEscalateReviewCase(): void
    {
        $eval = $this->createSampleEvaluation();
        $review = $this->reviewService->createReview($eval);

        $escalated = $this->reviewService->escalateReview($review->getId(), staffId: 88, notes: 'Requires L2 fraud analyst audit');

        $this->assertSame(RiskReviewStatus::ESCALATED, $escalated->getStatus());
        $this->assertSame('urgent', $escalated->getPriority());
        $this->assertTrue($escalated->isEscalated());
    }

    public function testApproveReviewGeneratesImmutableCryptographicSnapshot(): void
    {
        $eval = $this->createSampleEvaluation();
        $review = $this->reviewService->createReview($eval);

        $snapshot = $this->reviewService->approveReview(
            reviewId: $review->getId(),
            staffId: 99,
            reason: 'Customer verified via government ID and phone confirmation.',
            metadata: ['verification_method' => 'id_check', 'agent_notes' => 'passport approved']
        );

        $this->assertNotNull($snapshot->getId());
        $this->assertSame($review->getId(), $snapshot->getReviewId());
        $this->assertSame($eval->getId(), $snapshot->getEvaluationId());
        $this->assertSame('review', $snapshot->getOriginalDecision());
        $this->assertSame('approved', $snapshot->getFinalDecision());
        $this->assertTrue($snapshot->isOverride());
        $this->assertSame(99, $snapshot->getDecidedByStaffId());
        $this->assertTrue($snapshot->isApproved());
        $this->assertFalse($snapshot->isRejected());

        // Cryptographic integrity check
        $this->assertTrue($snapshot->verifyIntegrity());
        $this->assertTrue($this->reviewService->verifySnapshotIntegrity($review->getId()));

        // Review case status updated
        $updatedReview = $this->reviewService->getReview($review->getId());
        $this->assertNotNull($updatedReview);
        $this->assertSame(RiskReviewStatus::APPROVED, $updatedReview->getStatus());
        $this->assertTrue($updatedReview->isResolved());
        $this->assertSame(99, $updatedReview->getResolvedBy());
        $this->assertNotNull($updatedReview->getResolvedAt());
    }

    public function testRejectReviewRequiresReasonAndPersistsOutcome(): void
    {
        $eval = $this->createSampleEvaluation();
        $review = $this->reviewService->createReview($eval);

        // Blank reason should fail
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Manual decision reason must be provided.');
        $this->reviewService->rejectReview(reviewId: $review->getId(), staffId: 99, reason: '   ');
    }

    public function testRejectReviewSucceedsWithValidReason(): void
    {
        $eval = $this->createSampleEvaluation();
        $review = $this->reviewService->createReview($eval);

        $snapshot = $this->reviewService->rejectReview(
            reviewId: $review->getId(),
            staffId: 99,
            reason: 'Customer phone disconnected; chargeback risk confirmed.'
        );

        $this->assertSame('rejected', $snapshot->getFinalDecision());
        $this->assertTrue($snapshot->isRejected());
        $this->assertTrue($snapshot->verifyIntegrity());

        $updatedReview = $this->reviewService->getReview($review->getId());
        $this->assertSame(RiskReviewStatus::REJECTED, $updatedReview?->getStatus());
    }

    public function testTamperedSnapshotIntegrityFails(): void
    {
        $eval = $this->createSampleEvaluation();
        $review = $this->reviewService->createReview($eval);

        $snapshot = $this->reviewService->approveReview(
            reviewId: $review->getId(),
            staffId: 99,
            reason: 'Legitimate corporate customer'
        );

        $this->assertTrue($snapshot->verifyIntegrity());

        // Construct tampered instance with modified reason but original checksum
        $tampered = new RiskReviewDecisionSnapshot(
            id: $snapshot->getId(),
            reviewId: $snapshot->getReviewId(),
            evaluationId: $snapshot->getEvaluationId(),
            originalDecision: $snapshot->getOriginalDecision(),
            finalDecision: $snapshot->getFinalDecision(),
            isOverride: $snapshot->isOverride(),
            overrideReason: 'Forged modified reason after the fact',
            decidedByStaffId: $snapshot->getDecidedByStaffId(),
            decidedAt: $snapshot->getDecidedAt(),
            snapshotChecksum: $snapshot->getSnapshotChecksum()
        );

        $this->assertFalse($tampered->verifyIntegrity());
    }

    public function testResolvedCaseCannotBeMutated(): void
    {
        $eval = $this->createSampleEvaluation();
        $review = $this->reviewService->createReview($eval);

        $this->reviewService->approveReview($review->getId(), staffId: 99, reason: 'Approved');

        // Cannot re-approve
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('already been resolved');
        $this->reviewService->approveReview($review->getId(), staffId: 99, reason: 'Re-approve');
    }

    public function testPendingReviewsPrioritizationAndQueue(): void
    {
        $eval1 = $this->createSampleEvaluation();
        $reviewNormal = $this->reviewService->createReview($eval1, priority: 'normal');

        // Context 2
        $eval2 = $this->scoreService->evaluate(new RiskContext('order', 1002, 60, orderAmount: 500.0));
        $reviewUrgent = $this->reviewService->createReview($eval2, priority: 'urgent');

        // Context 3
        $eval3 = $this->scoreService->evaluate(new RiskContext('order', 1003, 61, orderAmount: 600.0));
        $reviewHigh = $this->reviewService->createReview($eval3, priority: 'high');

        $pending = $this->reviewService->getPendingReviews();
        $this->assertCount(3, $pending);

        // Must be sorted urgent -> high -> normal
        $this->assertSame($reviewUrgent->getId(), $pending[0]->getId());
        $this->assertSame($reviewHigh->getId(), $pending[1]->getId());
        $this->assertSame($reviewNormal->getId(), $pending[2]->getId());

        // Filter by priority
        $urgentOnly = $this->reviewService->getPendingReviews('urgent');
        $this->assertCount(1, $urgentOnly);
        $this->assertSame($reviewUrgent->getId(), $urgentOnly[0]->getId());
    }
}
