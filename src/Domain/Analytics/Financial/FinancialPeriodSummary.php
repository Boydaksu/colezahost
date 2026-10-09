<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Financial;

use JsonSerializable;

/**
 * Value object capturing the strict separation of
 * Invoiced Volume (claims), Cash Collected (settled funds), and Collections Efficiency.
 */
final class FinancialPeriodSummary implements JsonSerializable
{
    public function __construct(
        private readonly string $startDate,
        private readonly string $endDate,
        private readonly string $currency,
        private readonly float $grossInvoiced,
        private readonly float $netInvoiced,
        private readonly float $taxBilled,
        private readonly int $invoiceCount,
        private readonly int $paidInvoiceCount,
        private readonly int $unpaidInvoiceCount,
        private readonly float $grossCashCollected,
        private readonly float $cashRefunded,
        private readonly float $netCashFlow,
        private readonly float $collectionEfficiencyPercent
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

    public function getInvoiceCount(): int
    {
        return $this->invoiceCount;
    }

    public function getPaidInvoiceCount(): int
    {
        return $this->paidInvoiceCount;
    }

    public function getUnpaidInvoiceCount(): int
    {
        return $this->unpaidInvoiceCount;
    }

    public function getGrossCashCollected(): float
    {
        return $this->grossCashCollected;
    }

    public function getCashRefunded(): float
    {
        return $this->cashRefunded;
    }

    public function getNetCashFlow(): float
    {
        return $this->netCashFlow;
    }

    public function getCollectionEfficiencyPercent(): float
    {
        return $this->collectionEfficiencyPercent;
    }

    public function toArray(): array
    {
        return [
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'currency' => $this->currency,
            'gross_invoiced' => $this->grossInvoiced,
            'net_invoiced' => $this->netInvoiced,
            'tax_billed' => $this->taxBilled,
            'invoice_count' => $this->invoiceCount,
            'paid_invoice_count' => $this->paidInvoiceCount,
            'unpaid_invoice_count' => $this->unpaidInvoiceCount,
            'gross_cash_collected' => $this->grossCashCollected,
            'cash_refunded' => $this->cashRefunded,
            'net_cash_flow' => $this->netCashFlow,
            'collection_efficiency_percent' => $this->collectionEfficiencyPercent,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
