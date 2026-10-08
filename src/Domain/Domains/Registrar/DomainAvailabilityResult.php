<?php

declare(strict_types=1);

namespace Coleza\Domain\Domains\Registrar;

final class DomainAvailabilityResult
{
    public function __construct(
        private readonly string $domain,
        private readonly bool $isAvailable,
        private readonly bool $isPremium = false,
        private readonly ?int $premiumPriceMinor = null,
        private readonly string $currency = 'USD',
        private readonly ?string $reason = null,
        private readonly ?float $price = null
    ) {
    }

    public static function available(string $domain, ?float $price = null, string $currency = 'USD'): self
    {
        return new self($domain, true, false, null, $currency, null, $price);
    }

    public static function unavailable(string $domain, ?string $reason = null): self
    {
        return new self($domain, false, false, null, 'USD', $reason ?? 'Domain is already registered');
    }

    public static function premium(string $domain, int $premiumPriceMinor, string $currency = 'USD'): self
    {
        return new self($domain, true, true, $premiumPriceMinor, $currency);
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function isAvailable(): bool
    {
        return $this->isAvailable;
    }

    public function isPremium(): bool
    {
        return $this->isPremium;
    }

    public function getPremiumPriceMinor(): ?int
    {
        return $this->premiumPriceMinor;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getPrice(): ?float
    {
        return $this->price;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'domain' => $this->domain,
            'is_available' => $this->isAvailable,
            'is_premium' => $this->isPremium,
            'premium_price_minor' => $this->premiumPriceMinor,
            'price' => $this->price,
            'currency' => $this->currency,
            'reason' => $this->reason,
        ];
    }
}
