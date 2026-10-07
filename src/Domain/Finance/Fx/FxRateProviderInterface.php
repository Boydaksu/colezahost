<?php

declare(strict_types=1);

namespace Coleza\Domain\Finance\Fx;

interface FxRateProviderInterface
{
    public function getName(): string;

    /**
     * Fetch exchange rates against a base currency code.
     *
     * @param string $baseCurrency Base ISO 4217 currency code (e.g. 'TRY' or 'USD')
     * @param array<string> $targetCurrencies Target ISO codes
     * @return array<string, float> Map of targetCurrency => exchangeRate
     */
    public function getRates(string $baseCurrency, array $targetCurrencies): array;
}
