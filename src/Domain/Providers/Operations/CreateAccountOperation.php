<?php

declare(strict_types=1);

namespace Coleza\Domain\Providers\Operations;

use Coleza\Domain\Providers\Contracts\ProviderCapability;
use Coleza\Domain\Providers\DTO\ServerConnectionDto;

final class CreateAccountOperation implements OperationInterface
{
    /**
     * @param array<string, mixed> $resourceQuotas
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private int $serviceId,
        private string $serviceNumber,
        private string $username,
        private string $password,
        private ?string $domain,
        private string $packageIdentifier,
        private array $resourceQuotas = [],
        private ?ServerConnectionDto $server = null,
        private array $metadata = []
    ) {
    }

    public function getServiceId(): int
    {
        return $this->serviceId;
    }

    public function getOperationType(): string
    {
        return ProviderCapability::CREATE_ACCOUNT;
    }

    public function getServiceNumber(): string
    {
        return $this->serviceNumber;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function getPackageIdentifier(): string
    {
        return $this->packageIdentifier;
    }

    /**
     * @return array<string, mixed>
     */
    public function getResourceQuotas(): array
    {
        return $this->resourceQuotas;
    }

    public function getServer(): ?ServerConnectionDto
    {
        return $this->server;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'service_id' => $this->serviceId,
            'service_number' => $this->serviceNumber,
            'username' => $this->username,
            'password' => '****',
            'domain' => $this->domain,
            'package_identifier' => $this->packageIdentifier,
            'resource_quotas' => $this->resourceQuotas,
            'server' => $this->server?->toSafeArray(),
            'metadata' => $this->metadata,
        ];
    }
}
