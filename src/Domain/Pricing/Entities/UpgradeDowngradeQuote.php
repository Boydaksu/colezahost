<?php

declare(strict_types=1);

namespace Coleza\Domain\Pricing\Entities;

final class UpgradeDowngradeQuote
{
    public const TYPE_UPGRADE = 'upgrade';
    public const TYPE_DOWNGRADE = 'downgrade';
    public const TYPE_LATERAL = 'lateral';

    public function __construct(
        private string $type,
        private int $currentServiceId,
        private int $oldProductId,
        private int $newProductId,
        private string $currencyCode,
        private string $cycle,
        private int $daysRemaining,
        private int $totalPeriodDays,
        private int $oldProrataCreditMinor,
        private int $newProrataChargeMinor,
        private int $netDueMinor,
        private int $creditIssuedMinor,
        private ?string $effectiveDate = null
    ) {
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getCurrentServiceId(): int
    {
        return $this->currentServiceId;
    }

    public function getOldProductId(): int
    {
        return $this->oldProductId;
    }

    public function getNewProductId(): int
    {
        return $this->newProductId;
    }

    public function getCurrencyCode(): string
    {
        return strtoupper($this->currencyCode);
    }

    public function getCycle(): string
    {
        return $this->cycle;
    }

    public function getDaysRemaining(): int
    {
        return $this->daysRemaining;
    }

    public function getTotalPeriodDays(): int
    {
        return $this->totalPeriodDays;
    }

    public function getOldProrataCreditMinor(): int
    {
        return $this->oldProrataCreditMinor;
    }

    public function getNewProrataChargeMinor(): int
    {
        return $this->newProrataChargeMinor;
    }

    /**
     * Amount the customer must pay immediately (if upgrade charge > old credit).
     */
    public function getNetDueMinor(): int
    {
        return $this->netDueMinor;
    }

    /**
     * Amount to be credited to customer's balance/ledger (if old credit > new charge).
     */
    public function getCreditIssuedMinor(): int
    {
        return $this->creditIssuedMinor;
    }

    public function getEffectiveDate(): ?string
    {
        return $this->effectiveDate;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'current_service_id' => $this->currentServiceId,
            'old_product_id' => $this->oldProductId,
            'new_product_id' => $this->newProductId,
            'currency_code' => $this->getCurrencyCode(),
            'cycle' => $this->cycle,
            'days_remaining' => $this->daysRemaining,
            'total_period_days' => $this->totalPeriodDays,
            'old_prorata_credit_minor' => $this->oldProrataCreditMinor,
            'new_prorata_charge_minor' => $this->newProrataChargeMinor,
            'net_due_minor' => $this->netDueMinor,
            'credit_issued_minor' => $this->creditIssuedMinor,
            'effective_date' => $this->effectiveDate,
        ];
    }
}
