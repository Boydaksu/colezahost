<?php

declare(strict_types=1);

namespace Coleza\Domain\Tax\Entities;

final class TaxCalculationResult
{
    /**
     * @param array<array{name: string, rate_percent: float, tax_minor: int, is_inclusive: bool}> $taxLines
     */
    public function __construct(
        private int $subtotalMinor,
        private int $taxTotalMinor,
        private int $totalMinor,
        private bool $isExempt = false,
        private ?string $exemptionReason = null,
        private array $taxLines = []
    ) {
    }

    public function getSubtotalMinor(): int
    {
        return $this->subtotalMinor;
    }

    public function getTaxTotalMinor(): int
    {
        return $this->taxTotalMinor;
    }

    public function getTotalMinor(): int
    {
        return $this->totalMinor;
    }

    public function isExempt(): bool
    {
        return $this->isExempt;
    }

    public function getExemptionReason(): ?string
    {
        return $this->exemptionReason;
    }

    /**
     * @return array<array{name: string, rate_percent: float, tax_minor: int, is_inclusive: bool}>
     */
    public function getTaxLines(): array
    {
        return $this->taxLines;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'subtotal_minor' => $this->subtotalMinor,
            'tax_total_minor' => $this->taxTotalMinor,
            'total_minor' => $this->totalMinor,
            'is_exempt' => $this->isExempt,
            'exemption_reason' => $this->exemptionReason,
            'tax_lines' => $this->taxLines,
        ];
    }
}
