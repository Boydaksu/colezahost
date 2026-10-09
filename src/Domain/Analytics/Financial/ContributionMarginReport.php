<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Financial;

use JsonSerializable;

/**
 * Value object capturing Contribution Margin and direct cost economics.
 */
final class ContributionMarginReport implements JsonSerializable
{
    public function __construct(
        private readonly string $startDate,
        private readonly string $endDate,
        private readonly string $currency,
        private readonly float $grossCashCollected,
        private readonly float $cashRefunded,
        private readonly float $netCashFlow,
        private readonly float $gatewayFeesTotal,
        private readonly float $directCostsTotal,
        private readonly float $netContribution,
        private readonly float $contributionMarginPercent
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

    public function getGatewayFeesTotal(): float
    {
        return $this->gatewayFeesTotal;
    }

    public function getDirectCostsTotal(): float
    {
        return $this->directCostsTotal;
    }

    public function getNetContribution(): float
    {
        return $this->netContribution;
    }

    public function getContributionMarginPercent(): float
    {
        return $this->contributionMarginPercent;
    }

    public function toArray(): array
    {
        return [
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'currency' => $this->currency,
            'gross_cash_collected' => $this->grossCashCollected,
            'cash_refunded' => $this->cashRefunded,
            'net_cash_flow' => $this->netCashFlow,
            'gateway_fees_total' => $this->gatewayFeesTotal,
            'direct_costs_total' => $this->directCostsTotal,
            'net_contribution' => $this->netContribution,
            'contribution_margin_percent' => $this->contributionMarginPercent,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
