<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Orders;

final class OrderItem
{
    /**
     * @param array<int> $optionSubIds
     * @param array<int> $addonIds
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private ?int $id,
        private int $orderId,
        private int $productId,
        private string $productName,
        private string $cycle,
        private int $quantity,
        private int $unitPriceMinor,
        private int $unitSetupFeeMinor,
        private int $totalMinor,
        private array $optionSubIds = [],
        private array $addonIds = [],
        private array $metadata = []
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrderId(): int
    {
        return $this->orderId;
    }

    public function getProductId(): int
    {
        return $this->productId;
    }

    public function getProductName(): string
    {
        return $this->productName;
    }

    public function getCycle(): string
    {
        return $this->cycle;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getUnitPriceMinor(): int
    {
        return $this->unitPriceMinor;
    }

    public function getUnitSetupFeeMinor(): int
    {
        return $this->unitSetupFeeMinor;
    }

    public function getTotalMinor(): int
    {
        return $this->totalMinor;
    }

    /**
     * @return array<int>
     */
    public function getOptionSubIds(): array
    {
        return $this->optionSubIds;
    }

    /**
     * @return array<int>
     */
    public function getAddonIds(): array
    {
        return $this->addonIds;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->orderId,
            'product_id' => $this->productId,
            'product_name' => $this->productName,
            'cycle' => $this->cycle,
            'quantity' => $this->quantity,
            'unit_price_minor' => $this->unitPriceMinor,
            'unit_setup_fee_minor' => $this->unitSetupFeeMinor,
            'total_minor' => $this->totalMinor,
            'option_sub_ids' => $this->optionSubIds,
            'addon_ids' => $this->addonIds,
            'metadata' => $this->metadata,
        ];
    }
}
