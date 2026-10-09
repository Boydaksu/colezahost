<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Context;

use JsonSerializable;

/**
 * Value object capturing a transparent currency conversion for reporting.
 */
final class ConvertedAmountResult implements JsonSerializable
{
    public function __construct(
        private readonly float $originalAmount,
        private readonly string $fromCurrency,
        private readonly string $toCurrency,
        private readonly float $convertedAmount,
        private readonly float $exchangeRateUsed,
        private readonly string $rateDate
    ) {
    }

    public function getOriginalAmount(): float
    {
        return $this->originalAmount;
    }

    public function getFromCurrency(): string
    {
        return $this->fromCurrency;
    }

    public function getToCurrency(): string
    {
        return $this->toCurrency;
    }

    public function getConvertedAmount(): float
    {
        return $this->convertedAmount;
    }

    public function getExchangeRateUsed(): float
    {
        return $this->exchangeRateUsed;
    }

    public function getRateDate(): string
    {
        return $this->rateDate;
    }

    public function toArray(): array
    {
        return [
            'original_amount' => $this->originalAmount,
            'from_currency' => $this->fromCurrency,
            'to_currency' => $this->toCurrency,
            'converted_amount' => $this->convertedAmount,
            'exchange_rate_used' => $this->exchangeRateUsed,
            'rate_date' => $this->rateDate,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
