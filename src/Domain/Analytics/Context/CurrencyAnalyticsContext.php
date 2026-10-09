<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Context;

use Coleza\Foundation\Exceptions\ValidationException;

/**
 * Context guaranteeing Constitution rule:
 * "Historical currency/price/tax values are not silently recalculated with current settings."
 */
final class CurrencyAnalyticsContext
{
    /** @var array<string, array<string, float>> Map of 'YYYY-MM-DD' => ['FROM_TO' => rate] */
    private array $historicalRates = [];

    public function __construct(
        private readonly string $baseCurrency = 'USD'
    ) {
    }

    public function getBaseCurrency(): string
    {
        return $this->baseCurrency;
    }

    public function registerHistoricalRate(string $date, string $from, string $to, float $rate): void
    {
        $pair = strtoupper($from) . '_' . strtoupper($to);
        $this->historicalRates[$date][$pair] = $rate;

        // Also register inverse rate if not present
        $inversePair = strtoupper($to) . '_' . strtoupper($from);
        if (!isset($this->historicalRates[$date][$inversePair]) && $rate > 0) {
            $this->historicalRates[$date][$inversePair] = round(1.0 / $rate, 6);
        }
    }

    /**
     * Converts a historical monetary value into reporting target currency
     * using the frozen historical exchange rate active on the transaction date.
     */
    public function convertHistorical(
        float $amount,
        string $fromCurrency,
        string $toCurrency,
        string $transactionDate,
        ?float $overrideRate = null
    ): ConvertedAmountResult {
        $from = strtoupper(trim($fromCurrency));
        $to = strtoupper(trim($toCurrency));

        // Same currency requires no conversion
        if ($from === $to) {
            return new ConvertedAmountResult(
                originalAmount: $amount,
                fromCurrency: $from,
                toCurrency: $to,
                convertedAmount: $amount,
                exchangeRateUsed: 1.0,
                rateDate: $transactionDate
            );
        }

        $rate = $overrideRate;
        if ($rate === null) {
            $pair = $from . '_' . $to;
            $rate = $this->historicalRates[$transactionDate][$pair] ?? null;

            // Fallback: search closest prior date rate
            if ($rate === null) {
                $rate = $this->findClosestPriorRate($pair, $transactionDate);
            }
        }

        if ($rate === null || $rate <= 0) {
            $msg = sprintf('No historical exchange rate registered for %s to %s on %s.', $from, $to, $transactionDate);
            throw new ValidationException(
                ['currency_conversion' => [$msg]],
                $msg
            );
        }

        $converted = round($amount * $rate, 2);

        return new ConvertedAmountResult(
            originalAmount: $amount,
            fromCurrency: $from,
            toCurrency: $to,
            convertedAmount: $converted,
            exchangeRateUsed: $rate,
            rateDate: $transactionDate
        );
    }

    private function findClosestPriorRate(string $pair, string $date): ?float
    {
        $closestDate = null;
        foreach (array_keys($this->historicalRates) as $d) {
            if ($d <= $date && isset($this->historicalRates[$d][$pair])) {
                if ($closestDate === null || $d > $closestDate) {
                    $closestDate = $d;
                }
            }
        }

        return $closestDate !== null ? $this->historicalRates[$closestDate][$pair] : null;
    }
}
