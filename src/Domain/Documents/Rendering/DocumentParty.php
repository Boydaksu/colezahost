<?php

declare(strict_types=1);

namespace Coleza\Domain\Documents\Rendering;

final class DocumentParty
{
    /**
     * @param array<string, mixed> $customFields
     */
    public function __construct(
        private string $name,
        private ?string $companyName = null,
        private ?string $taxNumber = null,
        private ?string $taxOffice = null,
        private ?string $email = null,
        private ?string $phone = null,
        private ?string $addressLine1 = null,
        private ?string $addressLine2 = null,
        private ?string $city = null,
        private ?string $state = null,
        private ?string $postalCode = null,
        private string $countryCode = 'TR',
        private ?string $logoUrl = null,
        private ?string $website = null,
        private array $customFields = []
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCompanyName(): ?string
    {
        return $this->companyName;
    }

    public function getDisplayName(): string
    {
        if ($this->companyName !== null && trim($this->companyName) !== '') {
            return $this->companyName;
        }

        return $this->name;
    }

    public function getTaxNumber(): ?string
    {
        return $this->taxNumber;
    }

    public function getTaxOffice(): ?string
    {
        return $this->taxOffice;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
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

    public function getPostalCode(): ?string
    {
        return $this->postalCode;
    }

    public function getCountryCode(): string
    {
        return strtoupper($this->countryCode);
    }

    public function getLogoUrl(): ?string
    {
        return $this->logoUrl;
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCustomFields(): array
    {
        return $this->customFields;
    }

    public function getFormattedAddress(): string
    {
        $lines = array_filter([
            $this->addressLine1,
            $this->addressLine2,
            trim(($this->postalCode ? $this->postalCode . ' ' : '') . ($this->city ?? '') . ($this->state ? ', ' . $this->state : '')),
            $this->getCountryCode(),
        ]);

        return implode("\n", $lines);
    }
}
