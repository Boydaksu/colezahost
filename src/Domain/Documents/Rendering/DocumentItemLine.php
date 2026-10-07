<?php

declare(strict_types=1);

namespace Coleza\Domain\Documents\Rendering;

final class DocumentItemLine
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private string $description,
        private float $quantity,
        private float $unitPrice,
        private float $taxRate,
        private float $taxAmount,
        private float $discountAmount = 0.0,
        private ?float $lineTotal = null,
        private array $metadata = []
    ) {
        if ($this->lineTotal === null) {
            $subtotal = $this->quantity * $this->unitPrice;
            $this->lineTotal = round($subtotal - $this->discountAmount + $this->taxAmount, 2);
        }
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getQuantity(): float
    {
        return $this->quantity;
    }

    public function getUnitPrice(): float
    {
        return $this->unitPrice;
    }

    public function getTaxRate(): float
    {
        return $this->taxRate;
    }

    public function getTaxAmount(): float
    {
        return $this->taxAmount;
    }

    public function getDiscountAmount(): float
    {
        return $this->discountAmount;
    }

    public function getLineTotal(): float
    {
        return $this->lineTotal ?? 0.0;
    }

    public function getNetTotal(): float
    {
        return round(($this->quantity * $this->unitPrice) - $this->discountAmount, 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
