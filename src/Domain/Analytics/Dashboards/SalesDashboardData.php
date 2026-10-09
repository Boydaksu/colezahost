<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Dashboards;

use JsonSerializable;

final class SalesDashboardData implements JsonSerializable
{
    /**
     * @param array<string, mixed> $topSellingProducts
     */
    public function __construct(
        private readonly string $startDate,
        private readonly string $endDate,
        private readonly string $currency,
        private readonly int $newOrdersCount,
        private readonly float $newOrdersVolume,
        private readonly int $newCustomersCount,
        private readonly float $newMrrAdded,
        private readonly array $topSellingProducts = []
    ) {
    }

    public function getStartDate(): string
    {
        return $this->startDate;
    }

    public function getEndDate(): string
    {
        return $this->endDate;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getNewOrdersCount(): int
    {
        return $this->newOrdersCount;
    }

    public function getNewOrdersVolume(): float
    {
        return $this->newOrdersVolume;
    }

    public function getNewCustomersCount(): int
    {
        return $this->newCustomersCount;
    }

    public function getNewMrrAdded(): float
    {
        return $this->newMrrAdded;
    }

    public function getTopSellingProducts(): array
    {
        return $this->topSellingProducts;
    }

    public function toArray(): array
    {
        return [
            'type' => DashboardType::SALES->value,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'currency' => $this->currency,
            'new_orders_count' => $this->newOrdersCount,
            'new_orders_volume' => $this->newOrdersVolume,
            'new_customers_count' => $this->newCustomersCount,
            'new_mrr_added' => $this->newMrrAdded,
            'top_selling_products' => $this->topSellingProducts,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
