<?php

declare(strict_types=1);

namespace Coleza\Domain\Documents\Rendering;

final class DocumentTotals
{
    /**
     * @param array<DocumentTaxBreakdown> $taxBreakdowns
     */
    public function __construct(
        private float $subtotal,
        private float $discountTotal,
        private float $taxableTotal,
        private array $taxBreakdowns,
        private float $taxTotal,
        private float $grandTotal,
        private float $paidAmount = 0.0,
        private ?float $balanceDue = null
    ) {
        if ($this->balanceDue === null) {
            $this->balanceDue = max(0.0, round($this->grandTotal - $this->paidAmount, 2));
        }
    }

    public function getSubtotal(): float
    {
        return $this->subtotal;
    }

    public function getDiscountTotal(): float
    {
        return $this->discountTotal;
    }

    public function getTaxableTotal(): float
    {
        return $this->taxableTotal;
    }

    /**
     * @return array<DocumentTaxBreakdown>
     */
    public function getTaxBreakdowns(): array
    {
        return $this->taxBreakdowns;
    }

    public function getTaxTotal(): float
    {
        return $this->taxTotal;
    }

    public function getGrandTotal(): float
    {
        return $this->grandTotal;
    }

    public function getPaidAmount(): float
    {
        return $this->paidAmount;
    }

    public function getBalanceDue(): float
    {
        return $this->balanceDue ?? 0.0;
    }
}
