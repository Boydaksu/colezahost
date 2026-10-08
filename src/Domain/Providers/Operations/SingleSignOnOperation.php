<?php

declare(strict_types=1);

namespace Coleza\Domain\Providers\Operations;

use Coleza\Domain\Providers\Contracts\ProviderCapability;
use Coleza\Domain\Providers\DTO\ServerConnectionDto;

final class SingleSignOnOperation implements OperationInterface
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private int $serviceId,
        private string $username,
        private ?string $clientIp = null,
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
        return ProviderCapability::SINGLE_SIGN_ON;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getClientIp(): ?string
    {
        return $this->clientIp;
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
            'client_ip' => $this->clientIp,
            'server' => $this->server?->toSafeArray(),
            'metadata' => $this->metadata,
        ];
    }
}
