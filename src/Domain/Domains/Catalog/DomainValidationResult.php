<?php

declare(strict_types=1);

namespace Coleza\Domain\Domains\Catalog;

final class DomainValidationResult
{
    /**
     * @param array<int, string> $errors
     */
    public function __construct(
        private readonly bool $isValid,
        private readonly string $domainName,
        private readonly string $sld = '',
        private readonly string $tld = '',
        private readonly array $errors = []
    ) {
    }

    public static function success(string $domainName, string $sld, string $tld): self
    {
        return new self(
            isValid: true,
            domainName: strtolower(trim($domainName)),
            sld: strtolower(trim($sld)),
            tld: strtolower(trim($tld)),
            errors: []
        );
    }

    /**
     * @param array<int, string> $errors
     */
    public static function failed(string $domainName, array $errors, string $sld = '', string $tld = ''): self
    {
        return new self(
            isValid: false,
            domainName: strtolower(trim($domainName)),
            sld: strtolower(trim($sld)),
            tld: strtolower(trim($tld)),
            errors: $errors
        );
    }

    public function isValid(): bool
    {
        return $this->isValid;
    }

    public function getDomainName(): string
    {
        return $this->domainName;
    }

    public function getSld(): string
    {
        return $this->sld;
    }

    public function getTld(): string
    {
        return $this->tld;
    }

    /**
     * @return array<int, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getFirstError(): ?string
    {
        return $this->errors[0] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'is_valid' => $this->isValid,
            'domain_name' => $this->domainName,
            'sld' => $this->sld,
            'tld' => $this->tld,
            'errors' => $this->errors,
        ];
    }
}
