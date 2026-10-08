<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Conditions;

use Coleza\Domain\Automation\Triggers\TriggerContext;

final class ConditionGroup implements ConditionInterface
{
    private LogicalOperator $logicalOperator;

    /**
     * @param array<int, ConditionInterface> $conditions
     */
    public function __construct(
        LogicalOperator|string $logicalOperator = LogicalOperator::AND,
        private array $conditions = []
    ) {
        $this->logicalOperator = is_string($logicalOperator)
            ? LogicalOperator::fromString($logicalOperator)
            : $logicalOperator;
    }

    public static function and(ConditionInterface ...$conditions): self
    {
        return new self(LogicalOperator::AND, $conditions);
    }

    public static function or(ConditionInterface ...$conditions): self
    {
        return new self(LogicalOperator::OR, $conditions);
    }

    public function addCondition(ConditionInterface $condition): self
    {
        $this->conditions[] = $condition;
        return $this;
    }

    public function getLogicalOperator(): LogicalOperator
    {
        return $this->logicalOperator;
    }

    /**
     * @return array<int, ConditionInterface>
     */
    public function getConditions(): array
    {
        return $this->conditions;
    }

    public function evaluate(TriggerContext $context): bool
    {
        if (empty($this->conditions)) {
            return true;
        }

        if ($this->logicalOperator === LogicalOperator::AND) {
            foreach ($this->conditions as $condition) {
                if (!$condition->evaluate($context)) {
                    return false; // Short-circuit
                }
            }
            return true;
        }

        // LogicalOperator::OR
        foreach ($this->conditions as $condition) {
            if ($condition->evaluate($context)) {
                return true; // Short-circuit
            }
        }

        return false;
    }
}
