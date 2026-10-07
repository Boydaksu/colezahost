<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Services;

final class ServicePlacement
{
    public const STATUS_UNASSIGNED = 'unassigned';
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_PLACED = 'placed';
    public const STATUS_MIGRATING = 'migrating';
    public const STATUS_EVICTED = 'evicted';

    /**
     * @param array<string, mixed> $resourceQuotas
     */
    public function __construct(
        private ?int $id,
        private int $serviceId,
        private ?int $serverId,
        private ?int $serverPoolId,
        private ?int $locationId,
        private string $status = self::STATUS_UNASSIGNED,
        private ?string $packageIdentifier = null,
        private ?string $dedicatedIp = null,
        private ?string $hostname = null,
        private int $diskLimitMb = 0,
        private int $bandwidthLimitMb = 0,
        private array $resourceQuotas = [],
        private ?string $assignedAt = null,
        private ?string $releasedAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getServiceId(): int
    {
        return $this->serviceId;
    }

    public function getServerId(): ?int
    {
        return $this->serverId;
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

    public function isPlaced(): bool
    {
        return $this->status === self::STATUS_PLACED;
    }

    public function isReserved(): bool
    {
        return $this->status === self::STATUS_RESERVED;
    }

    public function isUnassigned(): bool
    {
        return $this->status === self::STATUS_UNASSIGNED;
    }

    public function isEvicted(): bool
    {
        return $this->status === self::STATUS_EVICTED;
    }

    public function getPackageIdentifier(): ?string
    {
        return $this->packageIdentifier;
    }

    public function getDedicatedIp(): ?string
    {
        return $this->dedicatedIp;
    }

    public function getHostname(): ?string
    {
        return $this->hostname;
    }

    public function getDiskLimitMb(): int
    {
        return $this->diskLimitMb;
    }

    public function getBandwidthLimitMb(): int
    {
        return $this->bandwidthLimitMb;
    }

    /**
     * @return array<string, mixed>
     */
    public function getResourceQuotas(): array
    {
        return $this->resourceQuotas;
    }

    public function getAssignedAt(): ?string
    {
        return $this->assignedAt;
    }

    public function getReleasedAt(): ?string
    {
        return $this->releasedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'service_id' => $this->serviceId,
            'server_id' => $this->serverId,
            'server_pool_id' => $this->serverPoolId,
            'location_id' => $this->locationId,
            'status' => $this->status,
            'package_identifier' => $this->packageIdentifier,
            'dedicated_ip' => $this->dedicatedIp,
            'hostname' => $this->hostname,
            'disk_limit_mb' => $this->diskLimitMb,
            'bandwidth_limit_mb' => $this->bandwidthLimitMb,
            'resource_quotas' => $this->resourceQuotas,
            'assigned_at' => $this->assignedAt,
            'released_at' => $this->releasedAt,
        ];
    }
}
