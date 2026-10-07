<?php

declare(strict_types=1);

namespace Coleza\Domain\Pricing\Entities;

final class PriceOverride
{
    public const SCOPE_CUSTOMER = 'customer'; // user_id or org_id
    public const SCOPE_SERVICE = 'service';   // service_id

    public const TYPE_FIXED = 'fixed';       // Replaces standard price with explicit minor unit amount
    public const TYPE_PERCENT = 'percent';   // Percentage discount (e.g. 15 for 15% discount)

    public function __construct(
        private ?int $id,
        private string $scope, // 'customer' or 'service'
        private int $scopeId,  // user_id/org_id or service_id
        private string $targetType, // 'product', 'option_sub', 'addon'
        private int $targetId,
        private string $overrideType, // 'fixed' or 'percent'
        private int $overrideValue,   // Minor units if fixed, or basis points / percentage
        private ?string $currencyCode = null, // null for percent, currency code if fixed
        private ?string $cycle = null,        // null applies to all cycles, or specific cycle
        private ?string $reason = null,
        private bool $isActive = true,
        private ?string $createdAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function getScopeId(): int
    {
        return $this->scopeId;
    }

    public function getTargetType(): string
    {
        return $this->targetType;
    }

    public function getTargetId(): int
    {
        return $this->targetId;
    }

    public function getOverrideType(): string
    {
        return $this->overrideType;
    }

    public function getOverrideValue(): int
    {
        return $this->overrideValue;
    }

    public function getCurrencyCode(): ?string
    {
        return $this->currencyCode ? strtoupper($this->currencyCode) : null;
    }

    public function getCycle(): ?string
    {
        return $this->cycle;
    }

    public function getReason(): ?string
    {
        return $this->reason;
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
     * Apply override to standard minor unit price.
     */
    public function apply(int $standardPriceMinor): int
    {
        if ($this->overrideType === self::TYPE_FIXED) {
            return max(0, $this->overrideValue);
        }

        // Percentage discount
        $discountAmount = (int)round(($standardPriceMinor * $this->overrideValue) / 100);
        return max(0, $standardPriceMinor - $discountAmount);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'scope' => $this->scope,
            'scope_id' => $this->scopeId,
            'target_type' => $this->targetType,
            'target_id' => $this->targetId,
            'override_type' => $this->overrideType,
            'override_value' => $this->overrideValue,
            'currency_code' => $this->getCurrencyCode(),
            'cycle' => $this->cycle,
            'reason' => $this->reason,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt,
        ];
    }
}
