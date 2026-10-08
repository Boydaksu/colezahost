<?php

declare(strict_types=1);

namespace Coleza\Domain\Providers\Operations;

use Coleza\Domain\Providers\Contracts\ProviderCapability;
use Coleza\Domain\Providers\DTO\ServerConnectionDto;

final class TerminateAccountOperation implements OperationInterface
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private int $serviceId,
        private string $username,
        private bool $keepBackup = false,
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
        return ProviderCapability::TERMINATE_ACCOUNT;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function isKeepBackup(): bool
    {
        return $this->keepBackup;
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
            'keep_backup' => $this->keepBackup,
            'server' => $this->server?->toSafeArray(),
            'metadata' => $this->metadata,
        ];
    }
}
