<?php

declare(strict_types=1);

namespace Coleza\Domain\Fraud\Review;

use DateTimeImmutable;

final class RiskReviewDecisionSnapshot
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly ?int $id,
        private readonly int $reviewId,
        private readonly int $evaluationId,
        private readonly string $originalDecision,
        private readonly string $finalDecision,
        private readonly bool $isOverride,
        private readonly string $overrideReason,
        private readonly int $decidedByStaffId,
        private readonly DateTimeImmutable $decidedAt,
        private readonly string $snapshotChecksum,
        private readonly array $metadata = []
    ) {
    }

    public static function create(
        int $reviewId,
        int $evaluationId,
        string $originalDecision,
        string $finalDecision,
        bool $isOverride,
        string $overrideReason,
        int $decidedByStaffId,
        ?DateTimeImmutable $decidedAt = null,
        array $metadata = [],
        ?int $id = null
    ): self {
        $timestamp = $decidedAt ?? new DateTimeImmutable();
        $checksum = self::calculateChecksum(
            reviewId: $reviewId,
            evaluationId: $evaluationId,
            originalDecision: $originalDecision,
            finalDecision: $finalDecision,
            isOverride: $isOverride,
            overrideReason: $overrideReason,
            decidedByStaffId: $decidedByStaffId,
            decidedAt: $timestamp
        );

        return new self(
            id: $id,
            reviewId: $reviewId,
            evaluationId: $evaluationId,
            originalDecision: $originalDecision,
            finalDecision: $finalDecision,
            isOverride: $isOverride,
            overrideReason: $overrideReason,
            decidedByStaffId: $decidedByStaffId,
            decidedAt: $timestamp,
            snapshotChecksum: $checksum,
            metadata: $metadata
        );
    }

    public static function calculateChecksum(
        int $reviewId,
        int $evaluationId,
        string $originalDecision,
        string $finalDecision,
        bool $isOverride,
        string $overrideReason,
        int $decidedByStaffId,
        DateTimeImmutable $decidedAt
    ): string {
        $payload = [
            'review_id' => $reviewId,
            'evaluation_id' => $evaluationId,
            'original_decision' => strtolower(trim($originalDecision)),
            'final_decision' => strtolower(trim($finalDecision)),
            'is_override' => $isOverride,
            'override_reason' => trim($overrideReason),
            'decided_by_staff_id' => $decidedByStaffId,
            'decided_at' => $decidedAt->format('Y-m-d H:i:s'),
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReviewId(): int
    {
        return $this->reviewId;
    }

    public function getEvaluationId(): int
    {
        return $this->evaluationId;
    }

    public function getOriginalDecision(): string
    {
        return $this->originalDecision;
    }

    public function getFinalDecision(): string
    {
        return $this->finalDecision;
    }

    public function isOverride(): bool
    {
        return $this->isOverride;
    }

    public function getOverrideReason(): string
    {
        return $this->overrideReason;
    }

    public function getDecidedByStaffId(): int
    {
        return $this->decidedByStaffId;
    }

    public function getDecidedAt(): DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function getSnapshotChecksum(): string
    {
        return $this->snapshotChecksum;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function isApproved(): bool
    {
        return strtolower($this->finalDecision) === 'approved';
    }

    public function isRejected(): bool
    {
        return strtolower($this->finalDecision) === 'rejected';
    }

    public function verifyIntegrity(): bool
    {
        $computed = self::calculateChecksum(
            reviewId: $this->reviewId,
            evaluationId: $this->evaluationId,
            originalDecision: $this->originalDecision,
            finalDecision: $this->finalDecision,
            isOverride: $this->isOverride,
            overrideReason: $this->overrideReason,
            decidedByStaffId: $this->decidedByStaffId,
            decidedAt: $this->decidedAt
        );

        return hash_equals($this->snapshotChecksum, $computed);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'review_id' => $this->reviewId,
            'evaluation_id' => $this->evaluationId,
            'original_decision' => $this->originalDecision,
            'final_decision' => $this->finalDecision,
            'is_override' => $this->isOverride,
            'override_reason' => $this->overrideReason,
            'decided_by_staff_id' => $this->decidedByStaffId,
            'decided_at' => $this->decidedAt->format('Y-m-d H:i:s'),
            'snapshot_checksum' => $this->snapshotChecksum,
            'metadata' => $this->metadata,
        ];
    }
}
