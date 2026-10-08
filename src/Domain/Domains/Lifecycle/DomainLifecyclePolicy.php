<?php

declare(strict_types=1);

namespace Coleza\Domain\Domains\Lifecycle;

final class DomainLifecyclePolicy
{
    /**
     * @param list<int> $reminderScheduleDays Days relative to expiry: negative = before, positive = after (e.g. [-30, -7, -1, 1, 5])
     */
    public function __construct(
        private readonly int $gracePeriodDays = 30,
        private readonly int $redemptionPeriodDays = 30,
        private readonly array $reminderScheduleDays = [-30, -7, -1, 1, 5]
    ) {
    }

    public function getGracePeriodDays(): int
    {
        return $this->gracePeriodDays;
    }

    public function getRedemptionPeriodDays(): int
    {
        return $this->redemptionPeriodDays;
    }

    /**
     * @return list<int>
     */
    public function getReminderScheduleDays(): array
    {
        return $this->reminderScheduleDays;
    }
}
