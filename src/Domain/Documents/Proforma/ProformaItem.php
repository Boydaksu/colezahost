<?php

declare(strict_types=1);

namespace Coleza\Domain\Documents\Proforma;

final class ProformaItem
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private ?int $id,
        private ?int $proformaId,
        private string $description,
        private int $quantity,
        private int $unitAmountMinor,
        private int $subtotalMinor,
        private float $taxRate,
        private int $taxAmountMinor,
        private int $totalMinor,
        private array $metadata = []
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProformaId(): ?int
    {
        return $this->proformaId;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getUnitAmountMinor(): int
    {
        return $this->unitAmountMinor;
    }

    public function getSubtotalMinor(): int
    {
        return $this->subtotalMinor;
    }

    public function getTaxRate(): float
    {
        return $this->taxRate;
    }

    public function getTaxAmountMinor(): int
    {
        return $this->taxAmountMinor;
    }

    public function getTotalMinor(): int
    {
        return $this->totalMinor;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
