<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Subscription;

use JsonSerializable;

/**
 * Value object capturing the MRR movement bridge/waterfall across a period:
 * Ending MRR = Starting MRR + New + Expansion - Contraction - Churn + Reactivation.
 */
final class MrrWaterfallReport implements JsonSerializable
{
    public function __construct(
        private readonly float $startingMrr,
        private readonly float $newMrr,
        private readonly float $expansionMrr,
        private readonly float $contractionMrr,
        private readonly float $churnedMrr,
        private readonly float $reactivationMrr,
        private readonly float $endingMrr,
        private readonly float $netGrowth,
        private readonly float $growthRatePercent,
        private readonly string $currency,
        private readonly string $startDate,
        private readonly string $endDate
    ) {
    }

    public function getStartingMrr(): float
    {
        return $this->startingMrr;
    }

    public function getNewMrr(): float
    {
        return $this->newMrr;
    }

    public function getExpansionMrr(): float
    {
        return $this->expansionMrr;
    }

    public function getContractionMrr(): float
    {
        return $this->contractionMrr;
    }

    public function getChurnedMrr(): float
    {
        return $this->churnedMrr;
    }

    public function getReactivationMrr(): float
    {
        return $this->reactivationMrr;
    }

    public function getEndingMrr(): float
    {
        return $this->endingMrr;
    }

    public function getNetGrowth(): float
    {
        return $this->netGrowth;
    }

    public function getGrowthRatePercent(): float
    {
        return $this->growthRatePercent;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getStartDate(): string
    {
        return $this->startDate;
    }

    public function getEndDate(): string
    {
        return $this->endDate;
    }

    public function toArray(): array
    {
        return [
            'starting_mrr' => $this->startingMrr,
            'new_mrr' => $this->newMrr,
            'expansion_mrr' => $this->expansionMrr,
            'contraction_mrr' => $this->contractionMrr,
            'churned_mrr' => $this->churnedMrr,
            'reactivation_mrr' => $this->reactivationMrr,
            'ending_mrr' => $this->endingMrr,
            'net_growth' => $this->netGrowth,
            'growth_rate_percent' => $this->growthRatePercent,
            'currency' => $this->currency,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
