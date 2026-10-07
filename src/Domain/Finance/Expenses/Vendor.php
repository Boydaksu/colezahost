<?php

declare(strict_types=1);

namespace Coleza\Domain\Finance\Expenses;

final class Vendor
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private ?int $id,
        private string $code,
        private string $name,
        private ?string $contactEmail = null,
        private ?string $contactPhone = null,
        private ?string $website = null,
        private ?string $taxNumber = null,
        private ?string $address = null,
        private ?string $countryCode = null,
        private ?string $notes = null,
        private bool $isActive = true,
        private array $metadata = [],
        private ?string $createdAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return strtoupper(trim($this->code));
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getContactEmail(): ?string
    {
        return $this->contactEmail;
    }

    public function getContactPhone(): ?string
    {
        return $this->contactPhone;
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function getTaxNumber(): ?string
    {
        return $this->taxNumber;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function getCountryCode(): ?string
    {
        return $this->countryCode;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
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
            'code' => $this->getCode(),
            'name' => $this->name,
            'contact_email' => $this->contactEmail,
            'contact_phone' => $this->contactPhone,
            'website' => $this->website,
            'tax_number' => $this->taxNumber,
            'address' => $this->address,
            'country_code' => $this->countryCode,
            'notes' => $this->notes,
            'is_active' => $this->isActive,
            'metadata' => $this->metadata,
            'created_at' => $this->createdAt,
        ];
    }
}
