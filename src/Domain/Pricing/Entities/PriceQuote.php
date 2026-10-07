<?php

declare(strict_types=1);

namespace Coleza\Domain\Pricing\Entities;

final class PriceQuote
{
    /**
     * @param array<array{name: string, type: string, cycle: string, price_minor: int, setup_minor: int}> $items
     */
    public function __construct(
        private string $currencyCode,
        private string $cycle,
        private int $basePriceMinor,
        private int $setupFeeMinor,
        private int $optionsTotalMinor,
        private int $addonsTotalMinor,
        private int $recurringTotalMinor,
        private int $firstPaymentTotalMinor,
        private array $items = []
    ) {
    }

    public function getCurrencyCode(): string
    {
        return strtoupper($this->currencyCode);
    }

    public function getCycle(): string
    {
        return $this->cycle;
    }

    public function getBasePriceMinor(): int
    {
        return $this->basePriceMinor;
    }

    public function getSetupFeeMinor(): int
    {
        return $this->setupFeeMinor;
    }

    public function getOptionsTotalMinor(): int
    {
        return $this->optionsTotalMinor;
    }

    public function getAddonsTotalMinor(): int
    {
        return $this->addonsTotalMinor;
    }

    public function getRecurringTotalMinor(): int
    {
        return $this->recurringTotalMinor;
    }

    public function getFirstPaymentTotalMinor(): int
    {
        return $this->firstPaymentTotalMinor;
    }

    /**
     * @return array<array{name: string, type: string, cycle: string, price_minor: int, setup_minor: int}>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'currency_code' => $this->getCurrencyCode(),
            'cycle' => $this->cycle,
            'base_price_minor' => $this->basePriceMinor,
            'setup_fee_minor' => $this->setupFeeMinor,
            'options_total_minor' => $this->optionsTotalMinor,
            'addons_total_minor' => $this->addonsTotalMinor,
            'recurring_total_minor' => $this->recurringTotalMinor,
            'first_payment_total_minor' => $this->firstPaymentTotalMinor,
            'items' => $this->items,
        ];
    }
}
