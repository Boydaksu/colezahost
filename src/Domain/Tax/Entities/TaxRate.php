<?php

declare(strict_types=1);

namespace Coleza\Domain\Tax\Entities;

final class TaxRate
{
    public const CALCULATION_EXCLUSIVE = 'exclusive'; // Tax added on top of base price (e.g. 100 + 20% = 120 total)
    public const CALCULATION_INCLUSIVE = 'inclusive'; // Tax included in advertised price (e.g. 120 total = 100 net + 20 tax)

    public function __construct(
        private ?int $id,
        private int $taxClassId,
        private int $taxZoneId,
        private string $name, // e.g., 'KDV %20', 'VAT 20%'
        private float $ratePercent, // e.g., 20.0 for 20%
        private string $calculationType = self::CALCULATION_EXCLUSIVE,
        private int $priority = 1,
        private bool $isActive = true,
        private ?string $createdAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTaxClassId(): int
    {
        return $this->taxClassId;
    }

    public function getTaxZoneId(): int
    {
        return $this->taxZoneId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getRatePercent(): float
    {
        return $this->ratePercent;
    }

    public function getCalculationType(): string
    {
        return $this->calculationType;
    }

    public function isInclusive(): bool
    {
        return $this->calculationType === self::CALCULATION_INCLUSIVE;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    /**
     * Compute tax breakdown from an input minor unit amount.
     *
     * @param int $amountMinor
     * @return array{net_minor: int, tax_minor: int, gross_minor: int}
     */
    public function compute(int $amountMinor): array
    {
        if ($this->ratePercent <= 0.0) {
            return [
                'net_minor' => $amountMinor,
                'tax_minor' => 0,
                'gross_minor' => $amountMinor,
            ];
        }

        if ($this->isInclusive()) {
            // Gross is the input amount: Net = Gross / (1 + rate/100)
            $net = (int)round($amountMinor / (1 + ($this->ratePercent / 100)));
            $tax = $amountMinor - $net;
            return [
                'net_minor' => $net,
                'tax_minor' => $tax,
                'gross_minor' => $amountMinor,
            ];
        }

        // Exclusive: Net is the input amount: Tax = Net * (rate/100), Gross = Net + Tax
        $tax = (int)round(($amountMinor * $this->ratePercent) / 100);
        return [
            'net_minor' => $amountMinor,
            'tax_minor' => $tax,
            'gross_minor' => $amountMinor + $tax,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tax_class_id' => $this->taxClassId,
            'tax_zone_id' => $this->taxZoneId,
            'name' => $this->name,
            'rate_percent' => $this->ratePercent,
            'calculation_type' => $this->calculationType,
            'priority' => $this->priority,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt,
        ];
    }
}
