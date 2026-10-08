<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Branching;

use Coleza\Domain\Automation\Actions\ActionResult;

final class BranchExecutionResult
{
    /**
     * @param array<int, ActionResult> $actionResults
     */
    public function __construct(
        private readonly bool $conditionPassed,
        private readonly string $branchTaken, // 'THEN', 'ELSE', or 'NONE'
        private readonly array $actionResults = []
    ) {
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

    public function allActionsSucceeded(): bool
    {
        foreach ($this->actionResults as $result) {
            if ($result->isFailed()) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'condition_passed' => $this->conditionPassed,
            'branch_taken' => $this->branchTaken,
            'action_results' => array_map(fn (ActionResult $r) => $r->toArray(), $this->actionResults),
        ];
    }
}
