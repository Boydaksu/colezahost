<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Services;

use Coleza\Domain\Commerce\Recurring\BillingPeriod;
use DateTimeImmutable;

final class ServiceBillingRelation
{
    public function __construct(
        private string $billingCycle,
        private int $recurringAmountMinor,
        private string $currencyCode,
        private string $registrationDate,
        private string $nextDueDate,
        private ?string $nextInvoiceDate = null,
        private bool $autoRenew = true,
        private int $gracePeriodDays = 7,
        private int $terminationGracePeriodDays = 30
    ) {
        if ($this->nextInvoiceDate === null) {
            $this->nextInvoiceDate = $this->calculateNextInvoiceDate();
        }
    }

    public function getBillingCycle(): string
    {
        return $this->billingCycle;
    }

    public function getRecurringAmountMinor(): int
    {
        return $this->recurringAmountMinor;
    }

    public function getCurrencyCode(): string
    {
        return strtoupper($this->currencyCode);
    }

    public function getRegistrationDate(): string
    {
        return $this->registrationDate;
    }

    public function getNextDueDate(): string
    {
        return $this->nextDueDate;
    }

    public function getNextInvoiceDate(): string
    {
        return $this->nextInvoiceDate ?? $this->nextDueDate;
    }

    public function isAutoRenew(): bool
    {
        return $this->autoRenew;
    }

    public function getGracePeriodDays(): int
    {
        return $this->gracePeriodDays;
    }

    public function getTerminationGracePeriodDays(): int
    {
        return $this->terminationGracePeriodDays;
    }

    /**
     * Calculate invoice generation lead date (e.g. 14 days before due date).
     */
    public function calculateNextInvoiceDate(int $leadDays = 14): string
    {
        $due = new DateTimeImmutable($this->nextDueDate);
        $lead = $due->modify("-{$leadDays} days");
        return $lead->format('Y-m-d');
    }

    /**
     * Determine if service is currently overdue relative to a reference date.
     */
    public function isOverdue(?string $referenceDate = null): bool
    {
        $ref = new DateTimeImmutable($referenceDate ?? 'now');
        $due = new DateTimeImmutable($this->nextDueDate);

        return $ref > $due;
    }

    /**
     * Compute number of days overdue (0 if not overdue).
     */
    public function daysOverdue(?string $referenceDate = null): int
    {
        $ref = new DateTimeImmutable($referenceDate ?? 'now');
        $due = new DateTimeImmutable($this->nextDueDate);

        if ($ref <= $due) {
            return 0;
        }

        return (int) $due->diff($ref)->format('%a');
    }

    /**
     * Check if service has exceeded grace period and is due for suspension.
     */
    public function isSuspensionDue(?string $referenceDate = null): bool
    {
        return $this->daysOverdue($referenceDate) >= $this->gracePeriodDays;
    }

    /**
     * Check if service has exceeded termination grace period and is due for termination.
     */
    public function isTerminationDue(?string $referenceDate = null): bool
    {
        return $this->daysOverdue($referenceDate) >= $this->terminationGracePeriodDays;
    }

    /**
     * Advance next due date for the next recurring cycle.
     */
    public function advanceCycle(): self
    {
        $newDueDate = BillingPeriod::calculateNextDueDate($this->nextDueDate, $this->billingCycle);
        $due = new DateTimeImmutable($newDueDate);
        $newInvoiceDate = $due->modify('-14 days')->format('Y-m-d');

        return new self(
            billingCycle: $this->billingCycle,
            recurringAmountMinor: $this->recurringAmountMinor,
            currencyCode: $this->currencyCode,
            registrationDate: $this->registrationDate,
            nextDueDate: $newDueDate,
            nextInvoiceDate: $newInvoiceDate,
            autoRenew: $this->autoRenew,
            gracePeriodDays: $this->gracePeriodDays,
            terminationGracePeriodDays: $this->terminationGracePeriodDays
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'billing_cycle' => $this->billingCycle,
            'recurring_amount_minor' => $this->recurringAmountMinor,
            'currency_code' => $this->currencyCode,
            'registration_date' => $this->registrationDate,
            'next_due_date' => $this->nextDueDate,
            'next_invoice_date' => $this->nextInvoiceDate,
            'auto_renew' => $this->autoRenew,
            'grace_period_days' => $this->gracePeriodDays,
            'termination_grace_period_days' => $this->terminationGracePeriodDays,
        ];
    }
}
