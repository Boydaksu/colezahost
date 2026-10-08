<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Engine;

use Coleza\Domain\Automation\Actions\ActionResult;

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
        private readonly float $executionTimeMs = 0.0
    ) {
    }

    public static function executed(
        string $ruleId,
        string $ruleName,
        bool $conditionPassed,
        string $branchTaken,
        array $actionResults,
        float $executionTimeMs
    ): self {
        return new self(
            $ruleId,
            $ruleName,
            true,
            null,
            $conditionPassed,
            $branchTaken,
            $actionResults,
            $executionTimeMs
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
            0.0
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
        return !$this->executed;
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
            'is_successful' => $this->isSuccessful(),
        ];
    }
}
