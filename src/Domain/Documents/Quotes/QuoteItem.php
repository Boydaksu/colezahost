<?php

declare(strict_types=1);

namespace Coleza\Domain\Documents\Quotes;

final class QuoteItem
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private ?int $id,
        private ?int $quoteId,
        private string $description,
        private int $quantity,
        private int $unitAmountMinor,
        private int $subtotalMinor,
        private float $taxRate,
        private int $taxAmountMinor,
        private int $totalMinor,
        private ?int $productId = null,
        private ?string $billingCycle = 'monthly',
        private array $metadata = []
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuoteId(): ?int
    {
        return $this->quoteId;
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

    public function getProductId(): ?int
    {
        return $this->productId;
    }

    public function getBillingCycle(): ?string
    {
        return $this->billingCycle;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
