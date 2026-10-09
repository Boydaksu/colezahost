<?php

declare(strict_types=1);

namespace Coleza\Domain\Fraud\Review;

use DateTimeImmutable;

final class RiskReviewCase
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $evaluationId,
        private readonly string $entityType,
        private readonly ?int $entityId,
        private readonly ?int $userId,
        private readonly ?int $organizationId,
        private readonly RiskReviewStatus $status,
        private readonly ?int $assignedStaffId = null,
        private readonly string $priority = 'normal',
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $resolvedAt = null,
        private readonly ?int $resolvedBy = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEvaluationId(): int
    {
        return $this->evaluationId;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getEntityId(): ?int
    {
        return $this->entityId;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getOrganizationId(): ?int
    {
        return $this->organizationId;
    }

    public function getStatus(): RiskReviewStatus
    {
        return $this->status;
    }

    public function getAssignedStaffId(): ?int
    {
        return $this->assignedStaffId;
    }

    public function getPriority(): string
    {
        return $this->priority;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt ?? new DateTimeImmutable();
    }

    public function getResolvedAt(): ?DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function getResolvedBy(): ?int
    {
        return $this->resolvedBy;
    }

    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    public function isInReview(): bool
    {
        return $this->status->isInReview();
    }

    public function isEscalated(): bool
    {
        return $this->status->isEscalated();
    }

    public function isResolved(): bool
    {
        return $this->status->isResolved();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'evaluation_id' => $this->evaluationId,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'user_id' => $this->userId,
            'organization_id' => $this->organizationId,
            'status' => $this->status->value,
            'assigned_staff_id' => $this->assignedStaffId,
            'priority' => $this->priority,
            'created_at' => $this->getCreatedAt()->format('Y-m-d H:i:s'),
            'resolved_at' => $this->resolvedAt?->format('Y-m-d H:i:s'),
            'resolved_by' => $this->resolvedBy,
        ];
    }
}
