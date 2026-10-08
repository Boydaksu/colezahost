<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Services\Lifecycle;

final class OverdueGracePolicy
{
    /**
     * @param int $gracePeriodDays Number of days past due before service is suspended (default: 7)
     * @param int $terminationGraceDays Number of days past due before service is permanently terminated (default: 30)
     * @param array<int> $reminderDays Days past due at which warning reminders should be sent (e.g. [1, 3, 5])
     * @param bool $allowAutoReactivationOnPayment Automatically unsuspend service when invoice is paid (default: true)
     * @param bool $requireApprovalForTermination Require manual approval before executing termination (default: false)
     * @param array<string> $exemptTags Tags that exempt a service from automatic suspension/termination (e.g. ['vip', 'exempt'])
     * @param bool $sendNotifications Whether to dispatch customer emails on lifecycle transitions (default: true)
     */
    public function __construct(
        private readonly int $gracePeriodDays = 7,
        private readonly int $terminationGraceDays = 30,
        private readonly array $reminderDays = [1, 3, 5],
        private readonly bool $allowAutoReactivationOnPayment = true,
        private readonly bool $requireApprovalForTermination = false,
        private readonly array $exemptTags = ['vip', 'exempt'],
        private readonly bool $sendNotifications = true
    ) {
    }

    public function getGracePeriodDays(): int
    {
        return $this->gracePeriodDays;
    }

    public function getTerminationGraceDays(): int
    {
        return $this->terminationGraceDays;
    }

    /**
     * @return array<int>
     */
    public function getReminderDays(): array
    {
        return $this->reminderDays;
    }

    public function isAutoReactivationAllowed(): bool
    {
        return $this->allowAutoReactivationOnPayment;
    }

    public function requiresApprovalForTermination(): bool
    {
        return $this->requireApprovalForTermination;
    }

    /**
     * @return array<string>
     */
    public function getExemptTags(): array
    {
        return $this->exemptTags;
    }

    public function shouldSendNotifications(): bool
    {
        return $this->sendNotifications;
    }
}
