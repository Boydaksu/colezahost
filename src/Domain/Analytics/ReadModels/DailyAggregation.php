<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\ReadModels;

use JsonSerializable;

/**
 * Pre-aggregated read model representing daily business metrics.
 */
final class DailyAggregation implements JsonSerializable
{
    public function __construct(
        private readonly ?int $id,
        private readonly string $date, // YYYY-MM-DD
        private readonly string $currency,
        private readonly float $grossInvoiced,
        private readonly float $netInvoiced,
        private readonly float $taxBilled,
        private readonly int $invoicesCount,
        private readonly float $cashCollected,
        private readonly float $cashRefunded,
        private readonly float $netCashFlow,
        private readonly float $gatewayFees,
        private readonly int $newOrdersCount,
        private readonly int $newCustomersCount,
        private readonly int $ticketsOpenedCount,
        private readonly int $ticketsResolvedCount,
        private readonly ?string $rebuiltAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDate(): string
    {
        return $this->date;
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

    public function getInvoicesCount(): int
    {
        return $this->invoicesCount;
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

    public function getNewOrdersCount(): int
    {
        return $this->newOrdersCount;
    }

    public function getNewCustomersCount(): int
    {
        return $this->newCustomersCount;
    }

    public function getTicketsOpenedCount(): int
    {
        return $this->ticketsOpenedCount;
    }

    public function getTicketsResolvedCount(): int
    {
        return $this->ticketsResolvedCount;
    }

    public function getRebuiltAt(): string
    {
        return $this->rebuiltAt ?? date('Y-m-d H:i:s');
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date,
            'currency' => $this->currency,
            'gross_invoiced' => $this->grossInvoiced,
            'net_invoiced' => $this->netInvoiced,
            'tax_billed' => $this->taxBilled,
            'invoices_count' => $this->invoicesCount,
            'cash_collected' => $this->cashCollected,
            'cash_refunded' => $this->cashRefunded,
            'net_cash_flow' => $this->netCashFlow,
            'gateway_fees' => $this->gatewayFees,
            'new_orders_count' => $this->newOrdersCount,
            'new_customers_count' => $this->newCustomersCount,
            'tickets_opened_count' => $this->ticketsOpenedCount,
            'tickets_resolved_count' => $this->ticketsResolvedCount,
            'rebuilt_at' => $this->getRebuiltAt(),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: isset($data['id']) ? (int) $data['id'] : null,
            date: (string) $data['date'],
            currency: (string) $data['currency'],
            grossInvoiced: (float) $data['gross_invoiced'],
            netInvoiced: (float) $data['net_invoiced'],
            taxBilled: (float) $data['tax_billed'],
            invoicesCount: (int) $data['invoices_count'],
            cashCollected: (float) $data['cash_collected'],
            cashRefunded: (float) $data['cash_refunded'],
            netCashFlow: (float) $data['net_cash_flow'],
            gatewayFees: (float) $data['gateway_fees'],
            newOrdersCount: (int) $data['new_orders_count'],
            newCustomersCount: (int) $data['new_customers_count'],
            ticketsOpenedCount: (int) $data['tickets_opened_count'],
            ticketsResolvedCount: (int) $data['tickets_resolved_count'],
            rebuiltAt: isset($data['rebuilt_at']) ? (string) $data['rebuilt_at'] : null
        );
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
