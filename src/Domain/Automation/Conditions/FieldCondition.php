<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Conditions;

use Coleza\Domain\Automation\Triggers\TriggerContext;

final class FieldCondition implements ConditionInterface
{
    private ConditionOperator $operator;

    public function __construct(
        private readonly string $field,
        ConditionOperator|string $operator,
        private readonly mixed $expectedValue = null
    ) {
        $this->operator = is_string($operator) ? ConditionOperator::fromString($operator) : $operator;
    }

    public function getField(): string
    {
        return $this->field;
    }

    public function getOperator(): ConditionOperator
    {
        return $this->operator;
    }

    public function getExpectedValue(): mixed
    {
        return $this->expectedValue;
    }

    public function evaluate(TriggerContext $context): bool
    {
        $actualValue = $context->get($this->field);
        return ConditionEvaluator::compare($actualValue, $this->operator, $this->expectedValue);
    }
}
