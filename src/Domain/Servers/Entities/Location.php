<?php

declare(strict_types=1);

namespace Coleza\Domain\Servers\Entities;

final class Location
{
    public function __construct(
        private ?int $id,
        private string $name,
        private string $slug,
        private string $countryCode,
        private string $city,
        private ?string $datacenter = null,
        private bool $isActive = true,
        private ?string $createdAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getCountryCode(): string
    {
        return strtoupper($this->countryCode);
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function getDatacenter(): ?string
    {
        return $this->datacenter;
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
            'name' => $this->name,
            'slug' => $this->slug,
            'country_code' => $this->getCountryCode(),
            'city' => $this->city,
            'datacenter' => $this->datacenter,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt,
        ];
    }
}
