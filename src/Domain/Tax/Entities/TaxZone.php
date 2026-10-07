<?php

declare(strict_types=1);

namespace Coleza\Domain\Tax\Entities;

final class TaxZone
{
    /**
     * @param array<string> $countryCodes 2-letter ISO 3166-1 alpha-2 codes (e.g. ['TR'], or ['FR', 'DE', 'NL'] for EU)
     * @param array<string> $stateCodes Optional states/provinces (e.g. ['CA', 'NY'])
     */
    public function __construct(
        private ?int $id,
        private string $code, // e.g., 'TR', 'EU', 'US_TAXABLE', 'GLOBAL'
        private string $name,
        private array $countryCodes = [],
        private array $stateCodes = [],
        private bool $isGlobal = false,
        private ?string $createdAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return strtoupper($this->code);
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return array<string>
     */
    public function getCountryCodes(): array
    {
        return $this->countryCodes;
    }

    /**
     * @return array<string>
     */
    public function getStateCodes(): array
    {
        return $this->stateCodes;
    }

    public function isGlobal(): bool
    {
        return $this->isGlobal;
    }

    public function matches(string $countryCode, ?string $stateCode = null): bool
    {
        if ($this->isGlobal) {
            return true;
        }

        $c = strtoupper($countryCode);
        if (!in_array($c, $this->countryCodes, true)) {
            return false;
        }

        if (!empty($this->stateCodes) && $stateCode !== null) {
            $s = strtoupper($stateCode);
            return in_array($s, $this->stateCodes, true);
        }

        return true;
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
            'country_codes' => $this->countryCodes,
            'state_codes' => $this->stateCodes,
            'is_global' => $this->isGlobal,
            'created_at' => $this->createdAt,
        ];
    }
}
