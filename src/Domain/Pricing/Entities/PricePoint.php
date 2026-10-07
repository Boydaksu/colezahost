<?php

declare(strict_types=1);

namespace Coleza\Domain\Pricing\Entities;

final class PricePoint
{
    public const TARGET_PRODUCT = 'product';
    public const TARGET_OPTION = 'option_sub';
    public const TARGET_ADDON = 'addon';

    public function __construct(
        private ?int $id,
        private string $targetType,
        private int $targetId,
        private string $currencyCode,
        private string $cycle,
        private int $priceMinor,
        private int $setupFeeMinor = 0,
        private bool $isActive = true,
        private ?string $createdAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTargetType(): string
    {
        return $this->targetType;
    }

    public function getTargetId(): int
    {
        return $this->targetId;
    }

    public function getCurrencyCode(): string
    {
        return strtoupper($this->currencyCode);
    }

    public function getCycle(): string
    {
        return $this->cycle;
    }

    public function getPriceMinor(): int
    {
        return $this->priceMinor;
    }

    public function getSetupFeeMinor(): int
    {
        return $this->setupFeeMinor;
    }

    public function getTotalFirstPaymentMinor(): int
    {
        return $this->priceMinor + $this->setupFeeMinor;
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
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'target_type' => $this->targetType,
            'target_id' => $this->targetId,
            'currency_code' => $this->getCurrencyCode(),
            'cycle' => $this->cycle,
            'price_minor' => $this->priceMinor,
            'setup_fee_minor' => $this->setupFeeMinor,
            'total_first_payment_minor' => $this->getTotalFirstPaymentMinor(),
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt,
        ];
    }
}
