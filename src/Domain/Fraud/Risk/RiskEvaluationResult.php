<?php

declare(strict_types=1);

namespace Coleza\Domain\Fraud\Risk;

use DateTimeImmutable;

final class RiskEvaluationResult
{
    /**
     * @param list<RiskSignal> $signals
     * @param array<string, mixed> $contextSnapshot
     */
    public function __construct(
        private readonly ?int $id,
        private readonly string $entityType,
        private readonly ?int $entityId,
        private readonly ?int $userId,
        private readonly ?int $organizationId,
        private readonly int $totalScore,
        private readonly RiskDecision $decision,
        private readonly array $signals,
        private readonly array $contextSnapshot = [],
        private readonly ?DateTimeImmutable $evaluatedAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getTotalScore(): int
    {
        return $this->totalScore;
    }

    public function getDecision(): RiskDecision
    {
        return $this->decision;
    }

    public function isAccept(): bool
    {
        return $this->decision->isAccept();
    }

    public function isReview(): bool
    {
        return $this->decision->isReview();
    }

    public function isReject(): bool
    {
        return $this->decision->isReject();
    }

    /**
     * @return list<RiskSignal>
     */
    public function getSignals(): array
    {
        return $this->signals;
    }

    public function getSignalsCount(): int
    {
        return count($this->signals);
    }

    public function hasSignal(string $ruleCode): bool
    {
        foreach ($this->signals as $signal) {
            if ($signal->getRuleCode() === $ruleCode) {
                return true;
            }
        }
        return false;
    }

    public function getSignal(string $ruleCode): ?RiskSignal
    {
        foreach ($this->signals as $signal) {
            if ($signal->getRuleCode() === $ruleCode) {
                return $signal;
            }
        }
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContextSnapshot(): array
    {
        return $this->contextSnapshot;
    }

    public function getEvaluatedAt(): DateTimeImmutable
    {
        return $this->evaluatedAt ?? new DateTimeImmutable();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'user_id' => $this->userId,
            'organization_id' => $this->organizationId,
            'total_score' => $this->totalScore,
            'decision' => $this->decision->value,
            'signals_count' => count($this->signals),
            'signals' => array_map(fn (RiskSignal $s) => $s->toArray(), $this->signals),
            'context_snapshot' => $this->contextSnapshot,
            'evaluated_at' => $this->getEvaluatedAt()->format('Y-m-d H:i:s'),
        ];
    }
}
