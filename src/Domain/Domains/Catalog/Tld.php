<?php

declare(strict_types=1);

namespace Coleza\Domain\Domains\Catalog;

use DateTimeImmutable;

final class Tld
{
    /**
     * @param array<string, mixed> $additionalFields
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly int $id,
        private readonly string $extension,
        private readonly bool $isActive = true,
        private readonly ?string $registrarId = null,
        private readonly int $minYears = 1,
        private readonly int $maxYears = 10,
        private readonly bool $isIdnSupported = false,
        private readonly bool $isEppRequired = true,
        private readonly bool $whoisPrivacyAllowed = true,
        private readonly bool $dnsManagementAllowed = true,
        private readonly bool $emailForwardingAllowed = true,
        private readonly int $gracePeriodDays = 30,
        private readonly int $redemptionPeriodDays = 30,
        private readonly array $additionalFields = [],
        private readonly array $metadata = [],
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null
    ) {
    }

    public static function normalizeExtension(string $extension): string
    {
        $trimmed = strtolower(trim($extension));
        if (!str_starts_with($trimmed, '.')) {
            $trimmed = '.' . $trimmed;
        }
        return $trimmed;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getExtension(): string
    {
        return $this->extension;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getRegistrarId(): ?string
    {
        return $this->registrarId;
    }

    public function getMinYears(): int
    {
        return $this->minYears;
    }

    public function getMaxYears(): int
    {
        return $this->maxYears;
    }

    public function isIdnSupported(): bool
    {
        return $this->isIdnSupported;
    }

    public function isEppRequired(): bool
    {
        return $this->isEppRequired;
    }

    public function isWhoisPrivacyAllowed(): bool
    {
        return $this->whoisPrivacyAllowed;
    }

    public function isDnsManagementAllowed(): bool
    {
        return $this->dnsManagementAllowed;
    }

    public function isEmailForwardingAllowed(): bool
    {
        return $this->emailForwardingAllowed;
    }

    public function getGracePeriodDays(): int
    {
        return $this->gracePeriodDays;
    }

    public function getRedemptionPeriodDays(): int
    {
        return $this->redemptionPeriodDays;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAdditionalFields(): array
    {
        return $this->additionalFields;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function supportsYears(int $years): bool
    {
        return $years >= $this->minYears && $years <= $this->maxYears;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'extension' => $this->extension,
            'is_active' => $this->isActive,
            'registrar_id' => $this->registrarId,
            'min_years' => $this->minYears,
            'max_years' => $this->maxYears,
            'is_idn_supported' => $this->isIdnSupported,
            'is_epp_required' => $this->isEppRequired,
            'whois_privacy_allowed' => $this->whoisPrivacyAllowed,
            'dns_management_allowed' => $this->dnsManagementAllowed,
            'email_forwarding_allowed' => $this->emailForwardingAllowed,
            'grace_period_days' => $this->gracePeriodDays,
            'redemption_period_days' => $this->redemptionPeriodDays,
            'additional_fields' => $this->additionalFields,
            'metadata' => $this->metadata,
            'created_at' => $this->createdAt?->format(DateTimeImmutable::ATOM),
            'updated_at' => $this->updatedAt?->format(DateTimeImmutable::ATOM),
        ];
    }
}
