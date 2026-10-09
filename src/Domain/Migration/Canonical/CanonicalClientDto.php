<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Canonical;

final class CanonicalClientDto implements CanonicalEntityInterface
{
    /**
     * @param array<string, mixed> $customFields
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private string $sourceId,
        private string $sourceSystem,
        private string $firstName,
        private string $lastName,
        private string $email,
        private ?string $companyName = null,
        private ?string $phoneNumber = null,
        private ?string $addressLine1 = null,
        private ?string $addressLine2 = null,
        private ?string $city = null,
        private ?string $state = null,
        private ?string $postcode = null,
        private ?string $countryCode = null,
        private string $currency = 'USD',
        private string $status = 'active',
        private bool $taxExempt = false,
        private ?string $createdAt = null,
        private array $customFields = [],
        private array $metadata = []
    ) {
    }

    public function getSourceId(): string
    {
        return $this->sourceId;
    }

    public function getSourceSystem(): string
    {
        return $this->sourceSystem;
    }

    public function getEntityType(): string
    {
        return 'client';
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function getFullName(): string
    {
        return trim("{$this->firstName} {$this->lastName}");
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getCompanyName(): ?string
    {
        return $this->companyName;
    }

    public function getPhoneNumber(): ?string
    {
        return $this->phoneNumber;
    }

    public function getAddressLine1(): ?string
    {
        return $this->addressLine1;
    }

    public function getAddressLine2(): ?string
    {
        return $this->addressLine2;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function getState(): ?string
    {
        return $this->state;
    }

    public function getPostcode(): ?string
    {
        return $this->postcode;
    }

    public function getCountryCode(): ?string
    {
        return $this->countryCode;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isTaxExempt(): bool
    {
        return $this->taxExempt;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCustomFields(): array
    {
        return $this->customFields;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function toArray(): array
    {
        return [
            'source_id' => $this->sourceId,
            'source_system' => $this->sourceSystem,
            'entity_type' => $this->getEntityType(),
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'full_name' => $this->getFullName(),
            'email' => $this->email,
            'company_name' => $this->companyName,
            'phone_number' => $this->phoneNumber,
            'address_line1' => $this->addressLine1,
            'address_line2' => $this->addressLine2,
            'city' => $this->city,
            'state' => $this->state,
            'postcode' => $this->postcode,
            'country_code' => $this->countryCode,
            'currency' => $this->currency,
            'status' => $this->status,
            'tax_exempt' => $this->taxExempt,
            'created_at' => $this->createdAt,
            'custom_fields' => $this->customFields,
            'metadata' => $this->metadata,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
