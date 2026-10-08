<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Delay;

use Coleza\Domain\Automation\Actions\ActionInterface;
use Coleza\Domain\Automation\Triggers\TriggerContext;
use DateTimeImmutable;
use InvalidArgumentException;

final class DelayedExecution
{
    private DelayStatus $status;
    private ?DateTimeImmutable $dispatchedAt = null;

    /**
     * @param array<int, ActionInterface> $actionsToExecute
     */
    public function __construct(
        private readonly string $id,
        private readonly string $ruleId,
        private readonly string $ruleName,
        private readonly TriggerContext $context,
        private readonly array $actionsToExecute,
        private readonly DateTimeImmutable $executeAt,
        private readonly DateTimeImmutable $scheduledAt = new DateTimeImmutable()
    ) {
        $this->status = DelayStatus::SCHEDULED;
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

    public function getExecuteAt(): DateTimeImmutable
    {
        return $this->executeAt;
    }

    public function getScheduledAt(): DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    public function getStatus(): DelayStatus
    {
        return $this->status;
    }

    public function getDispatchedAt(): ?DateTimeImmutable
    {
        return $this->dispatchedAt;
    }

    public function isDue(DateTimeImmutable $now): bool
    {
        return $this->status === DelayStatus::SCHEDULED && $now >= $this->executeAt;
    }

    public function markDispatched(): void
    {
        if ($this->status !== DelayStatus::SCHEDULED) {
            throw new InvalidArgumentException("Cannot dispatch execution in status '{$this->status->value}'");
        }

        $this->status = DelayStatus::DISPATCHED;
        $this->dispatchedAt = new DateTimeImmutable();
    }

    public function cancel(): void
    {
        if ($this->status !== DelayStatus::SCHEDULED) {
            return;
        }

        $this->status = DelayStatus::CANCELLED;
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
            'scheduled_at' => $this->scheduledAt->format(DateTimeImmutable::ATOM),
            'execute_at' => $this->executeAt->format(DateTimeImmutable::ATOM),
            'dispatched_at' => $this->dispatchedAt?->format(DateTimeImmutable::ATOM),
            'actions_count' => count($this->actionsToExecute),
        ];
    }
}
