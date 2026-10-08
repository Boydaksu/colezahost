<?php

declare(strict_types=1);

namespace Coleza\Domain\Providers\Operations;

use Coleza\Domain\Providers\Contracts\ProviderCapability;
use Coleza\Domain\Providers\DTO\ServerConnectionDto;

final class ChangePackageOperation implements OperationInterface
{
    /**
     * @param array<string, mixed> $resourceQuotas
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private int $serviceId,
        private string $username,
        private string $newPackageIdentifier,
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
        return ProviderCapability::CHANGE_PACKAGE;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getNewPackageIdentifier(): string
    {
        return $this->newPackageIdentifier;
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
            'username' => $this->username,
            'new_package_identifier' => $this->newPackageIdentifier,
            'resource_quotas' => $this->resourceQuotas,
            'server' => $this->server?->toSafeArray(),
            'metadata' => $this->metadata,
        ];
    }
}
