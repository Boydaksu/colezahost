<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Dashboards;

use Coleza\Domain\Analytics\Financial\AgingReceivablesReport;
use Coleza\Domain\Analytics\Financial\FinancialPeriodSummary;
use JsonSerializable;

final class FinanceDashboardData implements JsonSerializable
{
    public function __construct(
        private readonly FinancialPeriodSummary $periodSummary,
        private readonly AgingReceivablesReport $agingReceivables,
        private readonly float $estimatedVatLiability,
        private readonly float $gatewayFeesTotal,
        private readonly float $netMarginPercent
    ) {
    }

    public function getPeriodSummary(): FinancialPeriodSummary
    {
        return $this->periodSummary;
    }

    public function getAgingReceivables(): AgingReceivablesReport
    {
        return $this->agingReceivables;
    }

    public function getEstimatedVatLiability(): float
    {
        return $this->estimatedVatLiability;
    }

    public function getGatewayFeesTotal(): float
    {
        return $this->gatewayFeesTotal;
    }

    public function getNetMarginPercent(): float
    {
        return $this->netMarginPercent;
    }

    public function toArray(): array
    {
        return [
            'type' => DashboardType::FINANCE->value,
            'period_summary' => $this->periodSummary->toArray(),
            'aging_receivables' => $this->agingReceivables->toArray(),
            'estimated_vat_liability' => $this->estimatedVatLiability,
            'gateway_fees_total' => $this->gatewayFeesTotal,
            'net_margin_percent' => $this->netMarginPercent,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
