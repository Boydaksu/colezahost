<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Conditions;

use Coleza\Domain\Automation\Triggers\TriggerContext;

interface ConditionInterface
{
    /**
     * Evaluate condition against the trigger context.
     */
    public function evaluate(TriggerContext $context): bool;
}
