<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Execution;

use DateTimeImmutable;

final class AutomationRunRecord
{
    /**
     * @param array<int, array<string, mixed>> $actionResults
     * @param array<string, mixed> $contextSummary
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly string $runId,
        private readonly string $ruleId,
        private readonly string $ruleName,
        private readonly int $ruleVersion,
        private readonly string $eventName,
        private readonly string $correlationId,
        private readonly ExecutionMode $executionMode,
        private readonly RunStatus $status,
        private readonly bool $conditionPassed,
        private readonly string $branchTaken,
        private readonly array $actionResults = [],
        private readonly array $contextSummary = [],
        private readonly float $durationMs = 0.0,
        private readonly ?string $errorMessage = null,
        private readonly ?DateTimeImmutable $executedAt = null,
        private readonly array $metadata = []
    ) {
    }

    public function getRunId(): string
    {
        return $this->runId;
    }

    public function getRuleId(): string
    {
        return $this->ruleId;
    }

    public function getRuleName(): string
    {
        return $this->ruleName;
    }

    public function getRuleVersion(): int
    {
        return $this->ruleVersion;
    }

    public function getEventName(): string
    {
        return $this->eventName;
    }

    public function getCorrelationId(): string
    {
        return $this->correlationId;
    }

    public function getExecutionMode(): ExecutionMode
    {
        return $this->executionMode;
    }

    public function getStatus(): RunStatus
    {
        return $this->status;
    }

    public function isConditionPassed(): bool
    {
        return $this->conditionPassed;
    }

    public function getBranchTaken(): string
    {
        return $this->branchTaken;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getActionResults(): array
    {
        return $this->actionResults;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContextSummary(): array
    {
        return $this->contextSummary;
    }

    public function getDurationMs(): float
    {
        return $this->durationMs;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getExecutedAt(): DateTimeImmutable
    {
        return $this->executedAt ?? new DateTimeImmutable();
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'rule_id' => $this->ruleId,
            'rule_name' => $this->ruleName,
            'rule_version' => $this->ruleVersion,
            'event_name' => $this->eventName,
            'correlation_id' => $this->correlationId,
            'execution_mode' => $this->executionMode->value,
            'status' => $this->status->value,
            'condition_passed' => $this->conditionPassed,
            'branch_taken' => $this->branchTaken,
            'action_results' => $this->actionResults,
            'context_summary' => $this->contextSummary,
            'duration_ms' => $this->durationMs,
            'error_message' => $this->errorMessage,
            'executed_at' => $this->getExecutedAt()->format(DateTimeImmutable::ATOM),
            'metadata' => $this->metadata,
        ];
    }
}
