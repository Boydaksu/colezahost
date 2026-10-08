<?php

declare(strict_types=1);

namespace Coleza\Domain\Domains;

use Coleza\Foundation\Exceptions\ValidationException;
use DateTimeImmutable;

final class DomainContact
{
    public const TYPE_REGISTRANT = 'registrant';
    public const TYPE_ADMIN = 'admin';
    public const TYPE_TECH = 'tech';
    public const TYPE_BILLING = 'billing';

    public const VALID_TYPES = [
        self::TYPE_REGISTRANT,
        self::TYPE_ADMIN,
        self::TYPE_TECH,
        self::TYPE_BILLING,
    ];

    /**
     * @param array<string, mixed> $additionalFields
     */
    public function __construct(
        private readonly ?int $id,
        private readonly int $domainId,
        private readonly string $contactType,
        private readonly string $firstName,
        private readonly string $lastName,
        private readonly ?string $companyName = null,
        private readonly string $email = '',
        private readonly string $phone = '',
        private readonly ?string $fax = null,
        private readonly string $addressLine1 = '',
        private readonly ?string $addressLine2 = null,
        private readonly string $city = '',
        private readonly ?string $state = null,
        private readonly string $postalCode = '',
        private readonly string $countryCode = 'US',
        private readonly array $additionalFields = [],
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null
    ) {
    }

    public static function validateContactType(string $type): string
    {
        $normalized = strtolower(trim($type));
        if (!in_array($normalized, self::VALID_TYPES, true)) {
            throw new ValidationException(
                ['contact_type' => "Invalid contact type '{$type}'. Must be one of: " . implode(', ', self::VALID_TYPES)],
                'Invalid contact type'
            );
        }
        return $normalized;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getContactType(): string
    {
        return $this->contactType;
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

    public function getCompanyName(): ?string
    {
        return $this->companyName;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPhone(): string
    {
        return $this->phone;
    }

    public function getFax(): ?string
    {
        return $this->fax;
    }

    public function getAddressLine1(): string
    {
        return $this->addressLine1;
    }

    public function getAddressLine2(): ?string
    {
        return $this->addressLine2;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function getState(): ?string
    {
        return $this->state;
    }

    public function getPostalCode(): string
    {
        return $this->postalCode;
    }

    public function getCountryCode(): string
    {
        return strtoupper($this->countryCode);
    }

    /**
     * @return array<string, mixed>
     */
    public function getAdditionalFields(): array
    {
        return $this->additionalFields;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'domain_id' => $this->domainId,
            'contact_type' => $this->contactType,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'full_name' => $this->getFullName(),
            'company_name' => $this->companyName,
            'email' => $this->email,
            'phone' => $this->phone,
            'fax' => $this->fax,
            'address_line_1' => $this->addressLine1,
            'address_line_2' => $this->addressLine2,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postalCode,
            'country_code' => $this->getCountryCode(),
            'additional_fields' => $this->additionalFields,
            'created_at' => $this->createdAt?->format(DateTimeImmutable::ATOM),
            'updated_at' => $this->updatedAt?->format(DateTimeImmutable::ATOM),
        ];
    }
}
