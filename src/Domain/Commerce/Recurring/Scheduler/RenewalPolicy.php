<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Recurring\Scheduler;

final class RenewalPolicy
{
    /**
     * @param int $leadDays Number of days before service next due date to generate renewal invoice (default: 14)
     * @param bool $autoRenewOnly Whether to only generate for services with auto_renew enabled (default: true)
     * @param bool $sendNotification Whether to send renewal invoice notification email (default: true)
     * @param int $invoiceDueDays Days from invoice creation until invoice becomes due (null = use service next due date)
     */
    public function __construct(
        private readonly int $leadDays = 14,
        private readonly bool $autoRenewOnly = true,
        private readonly bool $sendNotification = true,
        private readonly ?int $invoiceDueDays = null
    ) {
    }

    public function getLeadDays(): int
    {
        return $this->leadDays;
    }

    public function isAutoRenewOnly(): bool
    {
        return $this->autoRenewOnly;
    }

    public function shouldSendNotification(): bool
    {
        return $this->sendNotification;
    }

    public function getInvoiceDueDays(): ?int
    {
        return $this->invoiceDueDays;
    }
}
