<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Engine;

use Coleza\Domain\Automation\Actions\ActionResult;
use Coleza\Domain\Automation\Execution\ExecutionMode;
use Coleza\Domain\Automation\Execution\RunStatus;

final class RuleExecutionResult
{
    /**
     * @param array<int, ActionResult> $actionResults
     */
    public function __construct(
        private readonly string $ruleId,
        private readonly string $ruleName,
        private readonly bool $executed,
        private readonly ?string $skippedReason = null,
        private readonly bool $conditionPassed = false,
        private readonly string $branchTaken = 'NONE',
        private readonly array $actionResults = [],
        private readonly float $executionTimeMs = 0.0,
        private readonly RunStatus $status = RunStatus::SUCCESS,
        private readonly ExecutionMode $executionMode = ExecutionMode::ACTIVE,
        private readonly ?string $approvalId = null,
        private readonly ?string $delayId = null
    ) {
    }

    public static function executed(
        string $ruleId,
        string $ruleName,
        bool $conditionPassed,
        string $branchTaken,
        array $actionResults,
        float $executionTimeMs,
        RunStatus $status = RunStatus::SUCCESS,
        ExecutionMode $executionMode = ExecutionMode::ACTIVE
    ): self {
        return new self(
            $ruleId,
            $ruleName,
            true,
            null,
            $conditionPassed,
            $branchTaken,
            $actionResults,
            $executionTimeMs,
            $status,
            $executionMode
        );
    }

    public static function skipped(
        string $ruleId,
        string $ruleName,
        string $reason
    ): self {
        return new self(
            $ruleId,
            $ruleName,
            false,
            $reason,
            false,
            'NONE',
            [],
            0.0,
            RunStatus::SKIPPED,
            ExecutionMode::ACTIVE
        );
    }

    public static function paused(
        string $ruleId,
        string $ruleName,
        string $reason
    ): self {
        return new self(
            $ruleId,
            $ruleName,
            false,
            $reason,
            false,
            'NONE',
            [],
            0.0,
            RunStatus::PAUSED,
            ExecutionMode::ACTIVE
        );
    }

    public static function pendingApproval(
        string $ruleId,
        string $ruleName,
        string $approvalId,
        bool $conditionPassed,
        string $branchTaken,
        float $executionTimeMs
    ): self {
        return new self(
            $ruleId,
            $ruleName,
            true,
            null,
            $conditionPassed,
            $branchTaken,
            [],
            $executionTimeMs,
            RunStatus::PENDING_APPROVAL,
            ExecutionMode::ACTIVE,
            $approvalId,
            null
        );
    }

    public static function delayed(
        string $ruleId,
        string $ruleName,
        string $delayId,
        bool $conditionPassed,
        string $branchTaken,
        float $executionTimeMs
    ): self {
        return new self(
            $ruleId,
            $ruleName,
            true,
            null,
            $conditionPassed,
            $branchTaken,
            [],
            $executionTimeMs,
            RunStatus::DELAYED,
            ExecutionMode::ACTIVE,
            null,
            $delayId
        );
    }

    public static function observed(
        string $ruleId,
        string $ruleName,
        bool $conditionPassed,
        string $branchTaken,
        array $simulatedActionResults,
        float $executionTimeMs
    ): self {
        return new self(
            $ruleId,
            $ruleName,
            true,
            null,
            $conditionPassed,
            $branchTaken,
            $simulatedActionResults,
            $executionTimeMs,
            RunStatus::OBSERVED,
            ExecutionMode::OBSERVE
        );
    }

    public static function dryRun(
        string $ruleId,
        string $ruleName,
        bool $conditionPassed,
        string $branchTaken,
        array $simulatedActionResults,
        float $executionTimeMs
    ): self {
        return new self(
            $ruleId,
            $ruleName,
            true,
            null,
            $conditionPassed,
            $branchTaken,
            $simulatedActionResults,
            $executionTimeMs,
            RunStatus::DRY_RUN,
            ExecutionMode::DRY_RUN
        );
    }

    public function getRuleId(): string
    {
        return $this->ruleId;
    }

    public function getRuleName(): string
    {
        return $this->ruleName;
    }

    public function isExecuted(): bool
    {
        return $this->executed;
    }

    public function isSkipped(): bool
    {
        return !$this->executed && $this->status !== RunStatus::PAUSED;
    }

    public function isPaused(): bool
    {
        return $this->status === RunStatus::PAUSED;
    }

    public function getSkippedReason(): ?string
    {
        return $this->skippedReason;
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
     * @return array<int, ActionResult>
     */
    public function getActionResults(): array
    {
        return $this->actionResults;
    }

    public function getStatus(): RunStatus
    {
        return $this->status;
    }

    public function getExecutionMode(): ExecutionMode
    {
        return $this->executionMode;
    }

    public function getApprovalId(): ?string
    {
        return $this->approvalId;
    }

    public function getDelayId(): ?string
    {
        return $this->delayId;
    }

    public function hasActionFailures(): bool
    {
        foreach ($this->actionResults as $result) {
            if ($result->isFailed()) {
                return true;
            }
        }
        return false;
    }

    public function isSuccessful(): bool
    {
        if ($this->status === RunStatus::PENDING_APPROVAL || $this->status === RunStatus::DELAYED) {
            return true;
        }

        return $this->executed && !$this->hasActionFailures();
    }

    public function getExecutionTimeMs(): float
    {
        return $this->executionTimeMs;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'rule_name' => $this->ruleName,
            'executed' => $this->executed,
            'skipped_reason' => $this->skippedReason,
            'condition_passed' => $this->conditionPassed,
            'branch_taken' => $this->branchTaken,
            'action_results' => array_map(fn (ActionResult $r) => $r->toArray(), $this->actionResults),
            'execution_time_ms' => $this->executionTimeMs,
            'status' => $this->status->value,
            'execution_mode' => $this->executionMode->value,
            'approval_id' => $this->approvalId,
            'delay_id' => $this->delayId,
            'is_successful' => $this->isSuccessful(),
        ];
    }
}
