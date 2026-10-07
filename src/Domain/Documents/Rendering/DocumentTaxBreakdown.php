<?php

declare(strict_types=1);

namespace Coleza\Domain\Documents\Rendering;

final class DocumentTaxBreakdown
{
    public function __construct(
        private float $taxRate,
        private float $taxableAmount,
        private float $taxAmount
    ) {
    }

    public function getTaxRate(): float
    {
        return $this->taxRate;
    }

    public function getTaxableAmount(): float
    {
        return $this->taxableAmount;
    }

    public function getTaxAmount(): float
    {
        return $this->taxAmount;
    }
}
