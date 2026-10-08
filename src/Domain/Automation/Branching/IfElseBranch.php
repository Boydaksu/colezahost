<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Branching;

use Coleza\Domain\Automation\Actions\ActionInterface;
use Coleza\Domain\Automation\Conditions\ConditionInterface;
use Coleza\Domain\Automation\Triggers\TriggerContext;

final class IfElseBranch
{
    /**
     * @param array<int, ActionInterface> $thenActions
     * @param array<int, ActionInterface> $elseActions
     */
    public function __construct(
        private readonly ConditionInterface $condition,
        private readonly array $thenActions = [],
        private readonly array $elseActions = []
    ) {
    }

    public function getCondition(): ConditionInterface
    {
        return $this->condition;
    }

    /**
     * @return array<int, ActionInterface>
     */
    public function getThenActions(): array
    {
        return $this->thenActions;
    }

    /**
     * @return array<int, ActionInterface>
     */
    public function getElseActions(): array
    {
        return $this->elseActions;
    }

    public function evaluateCondition(TriggerContext $context): bool
    {
        return $this->condition->evaluate($context);
    }

    public function determineBranch(TriggerContext $context): string
    {
        $passed = $this->evaluateCondition($context);

        if ($passed) {
            return 'THEN';
        }

        return !empty($this->elseActions) ? 'ELSE' : 'NONE';
    }

    /**
     * @return array<int, ActionInterface>
     */
    public function getActionsToExecute(TriggerContext $context): array
    {
        $branch = $this->determineBranch($context);

        return match ($branch) {
            'THEN' => $this->thenActions,
            'ELSE' => $this->elseActions,
            default => [],
        };
    }
}
