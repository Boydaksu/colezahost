<?php

declare(strict_types=1);

namespace Coleza\Domain\Pricing\Entities;

final class ProrataCalculation
{
    public function __construct(
        private int $daysUsed,
        private int $daysTotal,
        private int $amountMinor,
        private int $proratedMinor,
        private float $ratio
    ) {
    }

    public function getDaysUsed(): int
    {
        return $this->daysUsed;
    }

    public function getDaysTotal(): int
    {
        return $this->daysTotal;
    }

    public function getAmountMinor(): int
    {
        return $this->amountMinor;
    }

    public function getProratedMinor(): int
    {
        return $this->proratedMinor;
    }

    public function getRatio(): float
    {
        return $this->ratio;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'days_used' => $this->daysUsed,
            'days_total' => $this->daysTotal,
            'amount_minor' => $this->amountMinor,
            'prorated_minor' => $this->proratedMinor,
            'ratio' => $this->ratio,
        ];
    }
}
