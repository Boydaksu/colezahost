<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Dashboards;

use JsonSerializable;

final class OwnerDashboardData implements JsonSerializable
{
    /**
     * @param array<string, mixed> $mrrTrend
     * @param array<string, mixed> $cashTrend
     */
    public function __construct(
        private readonly string $asOfDate,
        private readonly string $currency,
        private readonly float $totalMrr,
        private readonly float $totalArr,
        private readonly float $netCashFlowLast30Days,
        private readonly float $netMrrGrowth,
        private readonly int $activeSubscriptions,
        private readonly int $activeCustomers,
        private readonly float $arpu,
        private readonly float $outstandingReceivables,
        private readonly array $mrrTrend = [],
        private readonly array $cashTrend = []
    ) {
    }

    public function getAsOfDate(): string
    {
        return $this->asOfDate;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getTotalMrr(): float
    {
        return $this->totalMrr;
    }

    public function getTotalArr(): float
    {
        return $this->totalArr;
    }

    public function getNetCashFlowLast30Days(): float
    {
        return $this->netCashFlowLast30Days;
    }

    public function getNetMrrGrowth(): float
    {
        return $this->netMrrGrowth;
    }

    public function getActiveSubscriptions(): int
    {
        return $this->activeSubscriptions;
    }

    public function getActiveCustomers(): int
    {
        return $this->activeCustomers;
    }

    public function getArpu(): float
    {
        return $this->arpu;
    }

    public function getOutstandingReceivables(): float
    {
        return $this->outstandingReceivables;
    }

    public function getMrrTrend(): array
    {
        return $this->mrrTrend;
    }

    public function getCashTrend(): array
    {
        return $this->cashTrend;
    }

    public function toArray(): array
    {
        return [
            'type' => DashboardType::OWNER->value,
            'as_of_date' => $this->asOfDate,
            'currency' => $this->currency,
            'total_mrr' => $this->totalMrr,
            'total_arr' => $this->totalArr,
            'net_cash_flow_last_30_days' => $this->netCashFlowLast30Days,
            'net_mrr_growth' => $this->netMrrGrowth,
            'active_subscriptions' => $this->activeSubscriptions,
            'active_customers' => $this->activeCustomers,
            'arpu' => $this->arpu,
            'outstanding_receivables' => $this->outstandingReceivables,
            'mrr_trend' => $this->mrrTrend,
            'cash_trend' => $this->cashTrend,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
