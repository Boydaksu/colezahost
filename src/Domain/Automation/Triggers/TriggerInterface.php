<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Triggers;

interface TriggerInterface
{
    /**
     * The type of trigger: "event", "schedule", "manual", etc.
     */
    public function getType(): string;

    /**
     * Unique identifier or descriptive name for this trigger.
     */
    public function getName(): string;

    /**
     * Whether this trigger matches the given trigger context.
     */
    public function matches(TriggerContext $context): bool;
}
