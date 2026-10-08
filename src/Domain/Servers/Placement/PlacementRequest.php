<?php

declare(strict_types=1);

namespace Coleza\Domain\Servers\Placement;

final class PlacementRequest
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private ?int $serviceId = null,
        private ?int $productId = null,
        private ?int $serverPoolId = null,
        private ?string $providerSlug = null,
        private ?int $locationId = null,
        private int $requiredDiskMb = 0,
        private int $requiredBandwidthMb = 0,
        private int $requiredMemoryMb = 0,
        private bool $requiredDedicatedIp = false,
        private ?int $preferredServerId = null,
        private array $metadata = []
    ) {
    }

    public function getServiceId(): ?int
    {
        return $this->serviceId;
    }

    public function getProductId(): ?int
    {
        return $this->productId;
    }

    public function getServerPoolId(): ?int
    {
        return $this->serverPoolId;
    }

    public function getProviderSlug(): ?string
    {
        return $this->providerSlug;
    }

    public function getLocationId(): ?int
    {
        return $this->locationId;
    }

    public function getRequiredDiskMb(): int
    {
        return $this->requiredDiskMb;
    }

    public function getRequiredBandwidthMb(): int
    {
        return $this->requiredBandwidthMb;
    }

    public function getRequiredMemoryMb(): int
    {
        return $this->requiredMemoryMb;
    }

    public function requiresDedicatedIp(): bool
    {
        return $this->requiredDedicatedIp;
    }

    public function getPreferredServerId(): ?int
    {
        return $this->preferredServerId;
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
            'product_id' => $this->productId,
            'server_pool_id' => $this->serverPoolId,
            'provider_slug' => $this->providerSlug,
            'location_id' => $this->locationId,
            'required_disk_mb' => $this->requiredDiskMb,
            'required_bandwidth_mb' => $this->requiredBandwidthMb,
            'required_memory_mb' => $this->requiredMemoryMb,
            'required_dedicated_ip' => $this->requiredDedicatedIp,
            'preferred_server_id' => $this->preferredServerId,
            'metadata' => $this->metadata,
        ];
    }
}
