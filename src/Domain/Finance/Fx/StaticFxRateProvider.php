<?php

declare(strict_types=1);

namespace Coleza\Domain\Finance\Fx;

final class StaticFxRateProvider implements FxRateProviderInterface
{
    /**
     * @param array<string, array<string, float>> $matrix Map of baseCurrency => [targetCurrency => rate]
     */
    public function __construct(
        private array $matrix = []
    ) {
    }

    public function getName(): string
    {
        return 'static';
    }

    public function setRate(string $baseCurrency, string $targetCurrency, float $rate): void
    {
        $base = strtoupper($baseCurrency);
        $target = strtoupper($targetCurrency);
        $this->matrix[$base][$target] = $rate;
    }

    public function getRates(string $baseCurrency, array $targetCurrencies): array
    {
        $base = strtoupper($baseCurrency);
        $rates = [];

        foreach ($targetCurrencies as $target) {
            $t = strtoupper($target);
            if ($base === $t) {
                $rates[$t] = 1.0;
                continue;
            }

            if (isset($this->matrix[$base][$t])) {
                $rates[$t] = (float)$this->matrix[$base][$t];
            } elseif (isset($this->matrix[$t][$base]) && $this->matrix[$t][$base] > 0) {
                // Invert rate if reciprocal exists
                $rates[$t] = round(1.0 / $this->matrix[$t][$base], 8);
            }
        }

        return $rates;
    }
}
