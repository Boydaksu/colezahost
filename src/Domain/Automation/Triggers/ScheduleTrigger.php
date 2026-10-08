<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Triggers;

final class ScheduleTrigger implements TriggerInterface
{
    /**
     * @param string $scheduleInterval e.g. "daily", "hourly", "weekly", "monthly", "custom"
     */
    public function __construct(
        private readonly string $scheduleInterval
    ) {
    }

    public function getType(): string
    {
        return 'schedule';
    }

    public function getName(): string
    {
        return 'schedule:' . $this->scheduleInterval;
    }

    public function getScheduleInterval(): string
    {
        return $this->scheduleInterval;
    }

    public function matches(TriggerContext $context): bool
    {
        $eventName = $context->getEventName();
        if ($eventName !== 'schedule' && $eventName !== 'cron' && !str_starts_with($eventName, 'schedule.')) {
            return false;
        }

        $interval = (string) ($context->get('schedule') ?? $context->get('interval') ?? $context->get('cron_interval') ?? '');

        if ($interval === $this->scheduleInterval) {
            return true;
        }

        if ($eventName === 'schedule.' . $this->scheduleInterval) {
            return true;
        }

        return false;
    }
}
