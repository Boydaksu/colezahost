<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Approval;

use Coleza\Domain\Automation\Actions\ActionInterface;
use Coleza\Domain\Automation\Triggers\TriggerContext;
use DateTimeImmutable;
use InvalidArgumentException;

final class PendingApproval
{
    private ApprovalStatus $status;
    private ?string $decidedBy = null;
    private ?string $decisionNotes = null;
    private ?DateTimeImmutable $decidedAt = null;

    /**
     * @param array<int, ActionInterface> $actionsToExecute
     */
    public function __construct(
        private readonly string $id,
        private readonly string $ruleId,
        private readonly string $ruleName,
        private readonly TriggerContext $context,
        private readonly array $actionsToExecute,
        private readonly ApprovalRequirement $requirement,
        private readonly DateTimeImmutable $requestedAt = new DateTimeImmutable(),
        private readonly ?DateTimeImmutable $expiresAt = null
    ) {
        $this->status = ApprovalStatus::PENDING;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getRuleId(): string
    {
        return $this->ruleId;
    }

    public function getRuleName(): string
    {
        return $this->ruleName;
    }

    public function getContext(): TriggerContext
    {
        return $this->context;
    }

    /**
     * @return array<int, ActionInterface>
     */
    public function getActionsToExecute(): array
    {
        return $this->actionsToExecute;
    }

    public function getRequirement(): ApprovalRequirement
    {
        return $this->requirement;
    }

    public function getStatus(): ApprovalStatus
    {
        return $this->status;
    }

    public function getRequestedAt(): DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getDecidedBy(): ?string
    {
        return $this->decidedBy;
    }

    public function getDecisionNotes(): ?string
    {
        return $this->decisionNotes;
    }

    public function getDecidedAt(): DateTimeImmutable|null
    {
        return $this->decidedAt;
    }

    public function approve(string $decidedBy, ?string $notes = null): void
    {
        if ($this->status !== ApprovalStatus::PENDING) {
            throw new InvalidArgumentException("Cannot approve request in status '{$this->status->value}'");
        }

        $this->status = ApprovalStatus::APPROVED;
        $this->decidedBy = $decidedBy;
        $this->decisionNotes = $notes;
        $this->decidedAt = new DateTimeImmutable();
    }

    public function reject(string $decidedBy, string $notes): void
    {
        if ($this->status !== ApprovalStatus::PENDING) {
            throw new InvalidArgumentException("Cannot reject request in status '{$this->status->value}'");
        }

        $this->status = ApprovalStatus::REJECTED;
        $this->decidedBy = $decidedBy;
        $this->decisionNotes = $notes;
        $this->decidedAt = new DateTimeImmutable();
    }

    public function expire(): void
    {
        if ($this->status !== ApprovalStatus::PENDING) {
            return;
        }

        $this->status = ApprovalStatus::EXPIRED;
        $this->decidedAt = new DateTimeImmutable();
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $now > $this->expiresAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'rule_id' => $this->ruleId,
            'rule_name' => $this->ruleName,
            'status' => $this->status->value,
            'required_role' => $this->requirement->getRequiredRole(),
            'reason' => $this->requirement->getReason(),
            'requested_at' => $this->requestedAt->format(DateTimeImmutable::ATOM),
            'expires_at' => $this->expiresAt?->format(DateTimeImmutable::ATOM),
            'decided_by' => $this->decidedBy,
            'decision_notes' => $this->decisionNotes,
            'decided_at' => $this->decidedAt?->format(DateTimeImmutable::ATOM),
            'actions_count' => count($this->actionsToExecute),
        ];
    }
}
