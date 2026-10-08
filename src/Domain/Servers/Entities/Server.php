<?php

declare(strict_types=1);

namespace Coleza\Domain\Servers\Entities;

use Coleza\Domain\Providers\DTO\ServerConnectionDto;

final class Server
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_MAINTENANCE = 'maintenance';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_FULL = 'full';

    /**
     * @param array<string> $assignedIpPool
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private ?int $id,
        private string $name,
        private string $hostname,
        private string $ipAddress,
        private string $providerSlug,
        private ?int $serverPoolId = null,
        private ?int $locationId = null,
        private string $status = self::STATUS_ACTIVE,
        private ?ServerCapacity $capacity = null,
        private ?int $port = null,
        private bool $secure = true,
        private string $authType = 'api_token',
        private ?string $authSecret = null,
        private array $assignedIpPool = [],
        private array $metadata = [],
        private ?string $createdAt = null
    ) {
        if ($this->capacity === null) {
            $this->capacity = new ServerCapacity();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getHostname(): string
    {
        return $this->hostname;
    }

    public function getIpAddress(): string
    {
        return $this->ipAddress;
    }

    public function getProviderSlug(): string
    {
        return $this->providerSlug;
    }

    public function getServerPoolId(): ?int
    {
        return $this->serverPoolId;
    }

    public function getLocationId(): ?int
    {
        return $this->locationId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCapacity(): ServerCapacity
    {
        return $this->capacity ?? new ServerCapacity();
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

    /**
     * @return array<string>
     */
    public function getAssignedIpPool(): array
    {
        return $this->assignedIpPool;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isMaintenance(): bool
    {
        return $this->status === self::STATUS_MAINTENANCE;
    }

    public function isDisabled(): bool
    {
        return $this->status === self::STATUS_DISABLED;
    }

    public function isFull(): bool
    {
        return $this->status === self::STATUS_FULL || $this->getCapacity()->isAtCapacity();
    }

    public function canAcceptPlacement(int $requiredDiskMb = 0, int $requiredBandwidthMb = 0): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        return $this->getCapacity()->canAcceptPlacement($requiredDiskMb, $requiredBandwidthMb);
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

    public function toServerConnectionDto(): ServerConnectionDto
    {
        return new ServerConnectionDto(
            serverId: $this->id,
            hostname: $this->hostname,
            ipAddress: $this->ipAddress,
            port: $this->port,
            secure: $this->secure,
            authType: $this->authType,
            authSecret: $this->authSecret,
            options: $this->metadata
        );
    }

    /**
     * @return array<string>
     */
    public static function supportedStatuses(): array
    {
        return [
            self::STATUS_ACTIVE,
            self::STATUS_MAINTENANCE,
            self::STATUS_DISABLED,
            self::STATUS_FULL,
        ];
    }

    public static function isValidStatus(string $status): bool
    {
        return in_array($status, self::supportedStatuses(), true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'hostname' => $this->hostname,
            'ip_address' => $this->ipAddress,
            'provider_slug' => $this->providerSlug,
            'server_pool_id' => $this->serverPoolId,
            'location_id' => $this->locationId,
            'status' => $this->status,
            'capacity' => $this->getCapacity()->toArray(),
            'port' => $this->port,
            'secure' => $this->secure,
            'auth_type' => $this->authType,
            'auth_secret' => $this->getMaskedSecret(),
            'assigned_ip_pool' => $this->assignedIpPool,
            'metadata' => $this->metadata,
            'created_at' => $this->createdAt,
        ];
    }
}
