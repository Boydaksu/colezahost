<?php

declare(strict_types=1);

namespace Coleza\Domain\Finance\Profitability;

final class ProfitabilityReport
{
    /**
     * @param array<string, int> $revenueByCategoryMinor
     * @param array<string, int> $expensesByCategoryMinor
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private string $periodStart,
        private string $periodEnd,
        private string $currencyCode,
        private int $grossRevenueMinor,
        private int $gatewayFeesMinor,
        private int $netRevenueMinor,
        private int $totalExpensesMinor,
        private int $grossProfitMinor,
        private float $profitMarginPercent,
        private array $revenueByCategoryMinor = [],
        private array $expensesByCategoryMinor = [],
        private array $metadata = []
    ) {
    }

    public function getPeriodStart(): string
    {
        return $this->periodStart;
    }

    public function getPeriodEnd(): string
    {
        return $this->periodEnd;
    }

    public function getCurrencyCode(): string
    {
        return strtoupper(trim($this->currencyCode));
    }

    public function getGrossRevenueMinor(): int
    {
        return $this->grossRevenueMinor;
    }

    public function getGatewayFeesMinor(): int
    {
        return $this->gatewayFeesMinor;
    }

    public function getNetRevenueMinor(): int
    {
        return $this->netRevenueMinor;
    }

    public function getTotalExpensesMinor(): int
    {
        return $this->totalExpensesMinor;
    }

    public function getGrossProfitMinor(): int
    {
        return $this->grossProfitMinor;
    }

    public function getProfitMarginPercent(): float
    {
        return $this->profitMarginPercent;
    }

    public function isProfitable(): bool
    {
        return $this->grossProfitMinor > 0;
    }

    /**
     * @return array<string, int>
     */
    public function getRevenueByCategoryMinor(): array
    {
        return $this->revenueByCategoryMinor;
    }

    /**
     * @return array<string, int>
     */
    public function getExpensesByCategoryMinor(): array
    {
        return $this->expensesByCategoryMinor;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'currency_code' => $this->getCurrencyCode(),
            'gross_revenue_minor' => $this->grossRevenueMinor,
            'gateway_fees_minor' => $this->gatewayFeesMinor,
            'net_revenue_minor' => $this->netRevenueMinor,
            'total_expenses_minor' => $this->totalExpensesMinor,
            'gross_profit_minor' => $this->grossProfitMinor,
            'profit_margin_percent' => $this->profitMarginPercent,
            'is_profitable' => $this->isProfitable(),
            'revenue_by_category_minor' => $this->revenueByCategoryMinor,
            'expenses_by_category_minor' => $this->expensesByCategoryMinor,
            'metadata' => $this->metadata,
        ];
    }
}
