<?php

declare(strict_types=1);

namespace Coleza\Domain\Installer;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Data transfer object for the primary hosting provider brand and organization identity.
 */
final class BrandSetupDto implements JsonSerializable
{
    public function __construct(
        private string $companyName,
        private string $brandName,
        private string $supportEmail,
        private ?string $domain = null,
        private ?string $address = null,
        private ?string $taxNumber = null
    ) {
        $this->companyName = trim($this->companyName);
        $this->brandName = trim($this->brandName);
        $this->supportEmail = strtolower(trim($this->supportEmail));
        $this->domain = $this->domain !== null ? strtolower(trim($this->domain)) : null;

        $this->validate();
    }

    private function validate(): void
    {
        if ($this->companyName === '') {
            throw new InvalidArgumentException('Company legal name cannot be empty.');
        }

        if ($this->brandName === '') {
            throw new InvalidArgumentException('Brand display name cannot be empty.');
        }

        if ($this->supportEmail === '' || !filter_var($this->supportEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException(sprintf('Invalid brand support email address [%s].', $this->supportEmail));
        }
    }

    public function getCompanyName(): string
    {
        return $this->companyName;
    }

    public function getBrandName(): string
    {
        return $this->brandName;
    }

    public function getSupportEmail(): string
    {
        return $this->supportEmail;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function getTaxNumber(): ?string
    {
        return $this->taxNumber;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'company_name' => $this->companyName,
            'brand_name' => $this->brandName,
            'support_email' => $this->supportEmail,
            'domain' => $this->domain,
            'address' => $this->address,
            'tax_number' => $this->taxNumber,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
