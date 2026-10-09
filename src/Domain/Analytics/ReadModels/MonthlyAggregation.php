<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\ReadModels;

use JsonSerializable;

/**
 * Pre-aggregated read model representing monthly business metrics.
 */
final class MonthlyAggregation implements JsonSerializable
{
    public function __construct(
        private readonly ?int $id,
        private readonly string $yearMonth, // YYYY-MM
        private readonly string $currency,
        private readonly float $grossInvoiced,
        private readonly float $netInvoiced,
        private readonly float $taxBilled,
        private readonly float $cashCollected,
        private readonly float $cashRefunded,
        private readonly float $netCashFlow,
        private readonly float $gatewayFees,
        private readonly float $endOfMonthMrr,
        private readonly float $endOfMonthArr,
        private readonly int $newSubscriptionsCount,
        private readonly int $churnedSubscriptionsCount,
        private readonly ?string $rebuiltAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getYearMonth(): string
    {
        return $this->yearMonth;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getGrossInvoiced(): float
    {
        return $this->grossInvoiced;
    }

    public function getNetInvoiced(): float
    {
        return $this->netInvoiced;
    }

    public function getTaxBilled(): float
    {
        return $this->taxBilled;
    }

    public function getCashCollected(): float
    {
        return $this->cashCollected;
    }

    public function getCashRefunded(): float
    {
        return $this->cashRefunded;
    }

    public function getNetCashFlow(): float
    {
        return $this->netCashFlow;
    }

    public function getGatewayFees(): float
    {
        return $this->gatewayFees;
    }

    public function getEndOfMonthMrr(): float
    {
        return $this->endOfMonthMrr;
    }

    public function getEndOfMonthArr(): float
    {
        return $this->endOfMonthArr;
    }

    public function getNewSubscriptionsCount(): int
    {
        return $this->newSubscriptionsCount;
    }

    public function getChurnedSubscriptionsCount(): int
    {
        return $this->churnedSubscriptionsCount;
    }

    public function getRebuiltAt(): string
    {
        return $this->rebuiltAt ?? date('Y-m-d H:i:s');
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'year_month' => $this->yearMonth,
            'currency' => $this->currency,
            'gross_invoiced' => $this->grossInvoiced,
            'net_invoiced' => $this->netInvoiced,
            'tax_billed' => $this->taxBilled,
            'cash_collected' => $this->cashCollected,
            'cash_refunded' => $this->cashRefunded,
            'net_cash_flow' => $this->netCashFlow,
            'gateway_fees' => $this->gatewayFees,
            'end_of_month_mrr' => $this->endOfMonthMrr,
            'end_of_month_arr' => $this->endOfMonthArr,
            'new_subscriptions_count' => $this->newSubscriptionsCount,
            'churned_subscriptions_count' => $this->churnedSubscriptionsCount,
            'rebuilt_at' => $this->getRebuiltAt(),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: isset($data['id']) ? (int) $data['id'] : null,
            yearMonth: (string) $data['year_month'],
            currency: (string) $data['currency'],
            grossInvoiced: (float) $data['gross_invoiced'],
            netInvoiced: (float) $data['net_invoiced'],
            taxBilled: (float) $data['tax_billed'],
            cashCollected: (float) $data['cash_collected'],
            cashRefunded: (float) $data['cash_refunded'],
            netCashFlow: (float) $data['net_cash_flow'],
            gatewayFees: (float) $data['gateway_fees'],
            endOfMonthMrr: (float) ($data['end_of_month_mrr'] ?? 0.0),
            endOfMonthArr: (float) ($data['end_of_month_arr'] ?? 0.0),
            newSubscriptionsCount: (int) ($data['new_subscriptions_count'] ?? 0),
            churnedSubscriptionsCount: (int) ($data['churned_subscriptions_count'] ?? 0),
            rebuiltAt: isset($data['rebuilt_at']) ? (string) $data['rebuilt_at'] : null
        );
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
