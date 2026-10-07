<?php

declare(strict_types=1);

namespace Coleza\Domain\Finance\Fx;

final class FxSnapshot
{
    /**
     * @param array<string, float> $rates Map of targetCurrency => exchangeRate (relative to base currency)
     */
    public function __construct(
        private ?int $id,
        private string $baseCurrency,
        private array $rates,
        private string $provider,
        private string $snapshotDate,
        private ?string $createdAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBaseCurrency(): string
    {
        return strtoupper($this->baseCurrency);
    }

    /**
     * @return array<string, float>
     */
    public function getRates(): array
    {
        return $this->rates;
    }

    public function getRate(string $targetCurrency): ?float
    {
        $target = strtoupper($targetCurrency);
        if ($target === $this->getBaseCurrency()) {
            return 1.0;
        }
        return $this->rates[$target] ?? null;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getSnapshotDate(): string
    {
        return $this->snapshotDate;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    /**
     * Convert an integer minor amount from base currency to target currency using this snapshot rate.
     */
    public function convertFromBase(int $amountMinor, string $targetCurrency): int
    {
        $target = strtoupper($targetCurrency);
        if ($target === $this->getBaseCurrency()) {
            return $amountMinor;
        }

        $rate = $this->getRate($target);
        if ($rate === null) {
            throw new \RuntimeException("Exchange rate not found for currency '{$target}' in snapshot.");
        }

        return (int)round($amountMinor * $rate);
    }

    /**
     * Convert an integer minor amount from target currency into base currency.
     */
    public function convertToBase(int $amountMinor, string $fromCurrency): int
    {
        $from = strtoupper($fromCurrency);
        if ($from === $this->getBaseCurrency()) {
            return $amountMinor;
        }

        $rate = $this->getRate($from);
        if ($rate === null || $rate <= 0) {
            throw new \RuntimeException("Exchange rate not found or zero for currency '{$from}' in snapshot.");
        }

        return (int)round($amountMinor / $rate);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'base_currency' => $this->getBaseCurrency(),
            'rates' => $this->rates,
            'provider' => $this->provider,
            'snapshot_date' => $this->snapshotDate,
            'created_at' => $this->createdAt,
        ];
    }
}
