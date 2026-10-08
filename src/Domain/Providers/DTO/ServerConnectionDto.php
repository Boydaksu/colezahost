<?php

declare(strict_types=1);

namespace Coleza\Domain\Providers\DTO;

final class ServerConnectionDto
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        private ?int $serverId,
        private string $hostname,
        private ?string $ipAddress = null,
        private ?int $port = null,
        private bool $secure = true,
        private string $authType = 'api_token',
        private ?string $authSecret = null,
        private array $options = []
    ) {
    }

    public function getServerId(): ?int
    {
        return $this->serverId;
    }

    public function getHostname(): string
    {
        return $this->hostname;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function isSecure(): bool
    {
        return $this->secure;
    }

    public function getAuthType(): string
    {
        return $this->authType;
    }

    public function getAuthSecret(): ?string
    {
        return $this->authSecret;
    }

    public function getMaskedSecret(): string
    {
        if ($this->authSecret === null || $this->authSecret === '') {
            return '[NOT_SET]';
        }
        $len = strlen($this->authSecret);
        if ($len <= 4) {
            return '****';
        }
        return substr($this->authSecret, 0, 2) . str_repeat('*', $len - 4) . substr($this->authSecret, -2);
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Safe serialization that does not leak plaintext authentication secrets.
     *
     * @return array<string, mixed>
     */
    public function toSafeArray(): array
    {
        return [
            'server_id' => $this->serverId,
            'hostname' => $this->hostname,
            'ip_address' => $this->ipAddress,
            'port' => $this->port,
            'secure' => $this->secure,
            'auth_type' => $this->authType,
            'auth_secret' => $this->getMaskedSecret(),
            'options' => $this->options,
        ];
    }
}
