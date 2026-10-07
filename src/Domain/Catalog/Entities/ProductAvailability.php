<?php

declare(strict_types=1);

namespace Coleza\Domain\Catalog\Entities;

final class ProductAvailability
{
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_OUT_OF_STOCK = 'out_of_stock';
    public const STATUS_HIDDEN = 'hidden';
    public const STATUS_RETIRED = 'retired';

    public function __construct(
        private int $productId,
        private string $status = self::STATUS_AVAILABLE,
        private bool $stockTrackingEnabled = false,
        private int $stockQuantity = 0,
        private bool $allowBackorders = false,
        private ?int $maxPerCustomer = null,
        private ?string $availableFrom = null,
        private ?string $availableUntil = null
    ) {
    }

    public function getProductId(): int
    {
        return $this->productId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isStockTrackingEnabled(): bool
    {
        return $this->stockTrackingEnabled;
    }

    public function getStockQuantity(): int
    {
        return $this->stockQuantity;
    }

    public function allowsBackorders(): bool
    {
        return $this->allowBackorders;
    }

    public function getMaxPerCustomer(): ?int
    {
        return $this->maxPerCustomer;
    }

    public function getAvailableFrom(): ?string
    {
        return $this->availableFrom;
    }

    public function getAvailableUntil(): ?string
    {
        return $this->availableUntil;
    }

    /**
     * Determine if product can currently be ordered.
     */
    public function isPurchasable(?string $currentTimestamp = null): bool
    {
        if ($this->status === self::STATUS_OUT_OF_STOCK || $this->status === self::STATUS_RETIRED) {
            return false;
        }

        $now = $currentTimestamp ?? date('Y-m-d H:i:s');

        if ($this->availableFrom !== null && $now < $this->availableFrom) {
            return false;
        }

        if ($this->availableUntil !== null && $now > $this->availableUntil) {
            return false;
        }

        if ($this->stockTrackingEnabled && $this->stockQuantity <= 0 && !$this->allowBackorders) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'status' => $this->status,
            'stock_tracking_enabled' => $this->stockTrackingEnabled,
            'stock_quantity' => $this->stockQuantity,
            'allow_backorders' => $this->allowBackorders,
            'max_per_customer' => $this->maxPerCustomer,
            'available_from' => $this->availableFrom,
            'available_until' => $this->availableUntil,
            'is_purchasable' => $this->isPurchasable(),
        ];
    }
}
