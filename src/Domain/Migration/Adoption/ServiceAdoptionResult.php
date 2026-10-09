<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Adoption;

use JsonSerializable;

final class ServiceAdoptionResult implements JsonSerializable
{
    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    public function __construct(
        private bool $success,
        private ?int $adoptedServiceId = null,
        private ?AdoptedProviderIdentity $providerIdentity = null,
        private array $errors = [],
        private array $warnings = []
    ) {
    }

    public static function successful(
        int $adoptedServiceId,
        AdoptedProviderIdentity $identity,
        array $warnings = []
    ): self {
        return new self(
            success: true,
            adoptedServiceId: $adoptedServiceId,
            providerIdentity: $identity,
            warnings: $warnings
        );
    }

    /**
     * @param list<string> $errors
     */
    public static function failed(array $errors): self
    {
        return new self(
            success: false,
            errors: $errors
        );
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getAdoptedServiceId(): ?int
    {
        return $this->adoptedServiceId;
    }

    public function getProviderIdentity(): ?AdoptedProviderIdentity
    {
        return $this->providerIdentity;
    }

    /**
     * @return list<string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @return list<string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'adopted_service_id' => $this->adoptedServiceId,
            'provider_identity' => $this->providerIdentity?->toArray(),
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
