<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Triggers;

final class ManualTrigger implements TriggerInterface
{
    public function __construct(
        private readonly ?string $targetScope = null
    ) {
    }

    public function getType(): string
    {
        return 'manual';
    }

    public function getName(): string
    {
        return $this->targetScope ? 'manual:' . $this->targetScope : 'manual';
    }

    public function matches(TriggerContext $context): bool
    {
        $eventName = $context->getEventName();
        if ($eventName !== 'manual' && $eventName !== 'manual.dispatch') {
            return false;
        }

        if ($this->targetScope !== null) {
            $contextScope = $context->get('scope') ?? $context->get('target_scope');
            return $contextScope === $this->targetScope;
        }

        return true;
    }
}
