<?php

declare(strict_types=1);

namespace Coleza\Domain\Fraud\Review;

use Coleza\Domain\Fraud\Risk\RiskEvaluationResult;
use Coleza\Domain\Fraud\Risk\RiskScoreService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use DateTimeImmutable;

final class RiskReviewService
{
    private string $reviewsTable = 'fraud_risk_reviews';
    private string $decisionsTable = 'fraud_risk_review_decisions';

    public function __construct(
        private readonly Connection $db,
        private readonly ?RiskScoreService $riskScoreService = null
    ) {
    }

    public function ensureTables(): void
    {
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        $sqlReviews = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                evaluation_id INT NOT NULL,
                entity_type VARCHAR(50) NOT NULL,
                entity_id INT NULL,
                user_id INT NULL,
                organization_id INT NULL,
                status VARCHAR(30) NOT NULL DEFAULT \'pending\',
                assigned_staff_id INT NULL,
                priority VARCHAR(20) NOT NULL DEFAULT \'normal\',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                resolved_at TIMESTAMP NULL,
                resolved_by INT NULL
            )',
            $this->reviewsTable,
            $autoInc
        );
        $this->db->statement($sqlReviews);

        $sqlDecisions = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                review_id INT NOT NULL,
                evaluation_id INT NOT NULL,
                original_decision VARCHAR(20) NOT NULL,
                final_decision VARCHAR(20) NOT NULL,
                is_override TINYINT(1) NOT NULL DEFAULT 0,
                override_reason TEXT NOT NULL,
                decided_by_staff_id INT NOT NULL,
                decided_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                snapshot_checksum VARCHAR(64) NOT NULL,
                metadata_json TEXT NULL
            )',
            $this->decisionsTable,
            $autoInc
        );
        $this->db->statement($sqlDecisions);

        if ($driver !== 'sqlite') {
            try {
                $this->db->statement("CREATE INDEX idx_fraud_reviews_eval ON {$this->reviewsTable} (evaluation_id)");
                $this->db->statement("CREATE INDEX idx_fraud_reviews_status ON {$this->reviewsTable} (status, priority)");
                $this->db->statement("CREATE INDEX idx_fraud_decisions_review ON {$this->decisionsTable} (review_id)");
            } catch (\Throwable) {
                // Ignore if indices exist
            }
        }
    }

    public function createReview(RiskEvaluationResult $evaluation, string $priority = 'normal'): RiskReviewCase
    {
        $this->ensureTables();

        if ($evaluation->getId() === null) {
            throw new ValidationException(['evaluation' => ['Cannot create a review case for an unsaved evaluation.']], 'Cannot create a review case for an unsaved evaluation.');
        }

        // Check if an existing review exists for this evaluation
        $existing = $this->getReviewByEvaluation($evaluation->getId());
        if ($existing !== null) {
            return $existing;
        }

        $now = new DateTimeImmutable();
        $data = [
            'evaluation_id' => $evaluation->getId(),
            'entity_type' => $evaluation->getEntityType(),
            'entity_id' => $evaluation->getEntityId(),
            'user_id' => $evaluation->getUserId(),
            'organization_id' => $evaluation->getOrganizationId(),
            'status' => RiskReviewStatus::PENDING->value,
            'assigned_staff_id' => null,
            'priority' => strtolower(trim($priority)),
            'created_at' => $now->format('Y-m-d H:i:s'),
        ];

        $id = (int) $this->db->insert($this->reviewsTable, $data);

        return new RiskReviewCase(
            id: $id,
            evaluationId: $evaluation->getId(),
            entityType: $evaluation->getEntityType(),
            entityId: $evaluation->getEntityId(),
            userId: $evaluation->getUserId(),
            organizationId: $evaluation->getOrganizationId(),
            status: RiskReviewStatus::PENDING,
            assignedStaffId: null,
            priority: strtolower(trim($priority)),
            createdAt: $now
        );
    }

    public function claimReview(int $reviewId, int $staffId): RiskReviewCase
    {
        $review = $this->getReviewOrFail($reviewId);
        if ($review->isResolved()) {
            throw new ValidationException(['review' => [sprintf('Review case #%d is already resolved.', $reviewId)]], sprintf('Review case #%d is already resolved.', $reviewId));
        }

        $this->db->statement(
            "UPDATE {$this->reviewsTable} SET assigned_staff_id = :staff_id, status = :status WHERE id = :id",
            [
                'staff_id' => $staffId,
                'status' => RiskReviewStatus::IN_REVIEW->value,
                'id' => $reviewId,
            ]
        );

        return $this->getReviewOrFail($reviewId);
    }

    public function assignReview(int $reviewId, int $staffId): RiskReviewCase
    {
        return $this->claimReview($reviewId, $staffId);
    }

    public function escalateReview(int $reviewId, int $staffId, string $notes = ''): RiskReviewCase
    {
        $review = $this->getReviewOrFail($reviewId);
        if ($review->isResolved()) {
            throw new ValidationException(['review' => [sprintf('Cannot escalate resolved review case #%d.', $reviewId)]], sprintf('Cannot escalate resolved review case #%d.', $reviewId));
        }

        $this->db->statement(
            "UPDATE {$this->reviewsTable} SET status = :status, priority = 'urgent' WHERE id = :id",
            [
                'status' => RiskReviewStatus::ESCALATED->value,
                'id' => $reviewId,
            ]
        );

        return $this->getReviewOrFail($reviewId);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function approveReview(
        int $reviewId,
        int $staffId,
        string $reason,
        array $metadata = []
    ): RiskReviewDecisionSnapshot {
        return $this->resolveReview(
            reviewId: $reviewId,
            staffId: $staffId,
            finalDecision: 'approved',
            reason: $reason,
            metadata: $metadata
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function rejectReview(
        int $reviewId,
        int $staffId,
        string $reason,
        array $metadata = []
    ): RiskReviewDecisionSnapshot {
        return $this->resolveReview(
            reviewId: $reviewId,
            staffId: $staffId,
            finalDecision: 'rejected',
            reason: $reason,
            metadata: $metadata
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function resolveReview(
        int $reviewId,
        int $staffId,
        string $finalDecision,
        string $reason,
        array $metadata = []
    ): RiskReviewDecisionSnapshot {
        $review = $this->getReviewOrFail($reviewId);
        if ($review->isResolved()) {
            throw new ValidationException(['review' => [sprintf('Review case #%d has already been resolved.', $reviewId)]], sprintf('Review case #%d has already been resolved.', $reviewId));
        }

        $trimmedReason = trim($reason);
        if ($trimmedReason === '') {
            throw new ValidationException(['reason' => ['Manual decision reason must be provided.']], 'Manual decision reason must be provided.');
        }

        // Fetch original evaluation to determine original decision
        $eval = $this->riskScoreService?->getEvaluation($review->getEvaluationId());
        $originalDecisionStr = $eval !== null ? $eval->getDecision()->value : 'review';

        // Override occurs if changing away from the automated assessment
        $isOverride = ($finalDecision === 'approved' && $originalDecisionStr !== 'accept')
            || ($finalDecision === 'rejected' && $originalDecisionStr !== 'reject');

        $now = new DateTimeImmutable();
        $targetStatus = $finalDecision === 'approved' ? RiskReviewStatus::APPROVED : RiskReviewStatus::REJECTED;

        // 1. Update review case status
        $this->db->statement(
            "UPDATE {$this->reviewsTable}
             SET status = :status, resolved_at = :resolved_at, resolved_by = :resolved_by
             WHERE id = :id",
            [
                'status' => $targetStatus->value,
                'resolved_at' => $now->format('Y-m-d H:i:s'),
                'resolved_by' => $staffId,
                'id' => $reviewId,
            ]
        );

        // 2. Generate immutable snapshot with cryptographic checksum
        $snapshot = RiskReviewDecisionSnapshot::create(
            reviewId: $reviewId,
            evaluationId: $review->getEvaluationId(),
            originalDecision: $originalDecisionStr,
            finalDecision: $finalDecision,
            isOverride: $isOverride,
            overrideReason: $trimmedReason,
            decidedByStaffId: $staffId,
            decidedAt: $now,
            metadata: $metadata
        );

        $decisionData = [
            'review_id' => $snapshot->getReviewId(),
            'evaluation_id' => $snapshot->getEvaluationId(),
            'original_decision' => $snapshot->getOriginalDecision(),
            'final_decision' => $snapshot->getFinalDecision(),
            'is_override' => $snapshot->isOverride() ? 1 : 0,
            'override_reason' => $snapshot->getOverrideReason(),
            'decided_by_staff_id' => $snapshot->getDecidedByStaffId(),
            'decided_at' => $snapshot->getDecidedAt()->format('Y-m-d H:i:s'),
            'snapshot_checksum' => $snapshot->getSnapshotChecksum(),
            'metadata_json' => !empty($metadata) ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
        ];

        $decisionId = (int) $this->db->insert($this->decisionsTable, $decisionData);

        return new RiskReviewDecisionSnapshot(
            id: $decisionId,
            reviewId: $snapshot->getReviewId(),
            evaluationId: $snapshot->getEvaluationId(),
            originalDecision: $snapshot->getOriginalDecision(),
            finalDecision: $snapshot->getFinalDecision(),
            isOverride: $snapshot->isOverride(),
            overrideReason: $snapshot->getOverrideReason(),
            decidedByStaffId: $snapshot->getDecidedByStaffId(),
            decidedAt: $snapshot->getDecidedAt(),
            snapshotChecksum: $snapshot->getSnapshotChecksum(),
            metadata: $snapshot->getMetadata()
        );
    }

    public function getReview(int $reviewId): ?RiskReviewCase
    {
        $this->ensureTables();

        $row = $this->db->selectOne("SELECT * FROM {$this->reviewsTable} WHERE id = :id LIMIT 1", ['id' => $reviewId]);
        return $row !== null ? $this->hydrateReview($row) : null;
    }

    public function getReviewOrFail(int $reviewId): RiskReviewCase
    {
        $review = $this->getReview($reviewId);
        if ($review === null) {
            throw new ValidationException(['review' => [sprintf('Risk review case #%d not found.', $reviewId)]], sprintf('Risk review case #%d not found.', $reviewId));
        }
        return $review;
    }

    public function getReviewByEvaluation(int $evaluationId): ?RiskReviewCase
    {
        $this->ensureTables();

        $row = $this->db->selectOne(
            "SELECT * FROM {$this->reviewsTable} WHERE evaluation_id = :eval_id LIMIT 1",
            ['eval_id' => $evaluationId]
        );

        return $row !== null ? $this->hydrateReview($row) : null;
    }

    /**
     * @return list<RiskReviewCase>
     */
    public function getPendingReviews(?string $priority = null): array
    {
        $this->ensureTables();

        $sql = "SELECT * FROM {$this->reviewsTable} WHERE status IN ('pending', 'in_review', 'escalated')";
        $params = [];

        if ($priority !== null) {
            $sql .= " AND priority = :priority";
            $params['priority'] = strtolower(trim($priority));
        }

        $sql .= " ORDER BY CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 ELSE 4 END ASC, id ASC";

        $rows = $this->db->select($sql, $params);
        return array_map(fn (array $r) => $this->hydrateReview($r), $rows);
    }

    public function getDecisionSnapshot(int $reviewId): ?RiskReviewDecisionSnapshot
    {
        $this->ensureTables();

        $row = $this->db->selectOne(
            "SELECT * FROM {$this->decisionsTable} WHERE review_id = :review_id ORDER BY id DESC LIMIT 1",
            ['review_id' => $reviewId]
        );

        return $row !== null ? $this->hydrateSnapshot($row) : null;
    }

    public function verifySnapshotIntegrity(int $reviewId): bool
    {
        $snapshot = $this->getDecisionSnapshot($reviewId);
        if ($snapshot === null) {
            return false;
        }

        return $snapshot->verifyIntegrity();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateReview(array $row): RiskReviewCase
    {
        return new RiskReviewCase(
            id: (int) $row['id'],
            evaluationId: (int) $row['evaluation_id'],
            entityType: (string) $row['entity_type'],
            entityId: isset($row['entity_id']) ? (int) $row['entity_id'] : null,
            userId: isset($row['user_id']) ? (int) $row['user_id'] : null,
            organizationId: isset($row['organization_id']) ? (int) $row['organization_id'] : null,
            status: RiskReviewStatus::from((string) $row['status']),
            assignedStaffId: isset($row['assigned_staff_id']) ? (int) $row['assigned_staff_id'] : null,
            priority: (string) $row['priority'],
            createdAt: !empty($row['created_at']) ? new DateTimeImmutable((string) $row['created_at']) : null,
            resolvedAt: !empty($row['resolved_at']) ? new DateTimeImmutable((string) $row['resolved_at']) : null,
            resolvedBy: isset($row['resolved_by']) ? (int) $row['resolved_by'] : null
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateSnapshot(array $row): RiskReviewDecisionSnapshot
    {
        $meta = !empty($row['metadata_json'])
            ? json_decode((string) $row['metadata_json'], true) ?? []
            : [];

        return new RiskReviewDecisionSnapshot(
            id: (int) $row['id'],
            reviewId: (int) $row['review_id'],
            evaluationId: (int) $row['evaluation_id'],
            originalDecision: (string) $row['original_decision'],
            finalDecision: (string) $row['final_decision'],
            isOverride: (bool) $row['is_override'],
            overrideReason: (string) $row['override_reason'],
            decidedByStaffId: (int) $row['decided_by_staff_id'],
            decidedAt: new DateTimeImmutable((string) $row['decided_at']),
            snapshotChecksum: (string) $row['snapshot_checksum'],
            metadata: $meta
        );
    }
}
