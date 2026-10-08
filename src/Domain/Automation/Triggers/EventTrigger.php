<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Triggers;

final class EventTrigger implements TriggerInterface
{
    /**
     * @param string $eventPattern Pattern or exact name (e.g. "service.status_changed", "order.*", "*")
     * @param array<string, mixed> $payloadFilters Optional exact or equality filters on payload keys
     */
    public function __construct(
        private readonly string $eventPattern,
        private readonly array $payloadFilters = []
    ) {
    }

    public function getType(): string
    {
        return 'event';
    }

    public function getName(): string
    {
        return $this->eventPattern;
    }

    public function matches(TriggerContext $context): bool
    {
        $eventName = $context->getEventName();

        if (!$this->matchesPattern($this->eventPattern, $eventName)) {
            return false;
        }

        foreach ($this->payloadFilters as $key => $expectedValue) {
            $actualValue = $context->get($key);
            if ($actualValue !== $expectedValue) {
                return false;
            }
        }

        return true;
    }

    private function matchesPattern(string $pattern, string $value): bool
    {
        if ($pattern === '*' || $pattern === $value) {
            return true;
        }

        if (str_ends_with($pattern, '.*')) {
            $prefix = substr($pattern, 0, -2);
            return str_starts_with($value, $prefix . '.');
        }

        return false;
    }
}
