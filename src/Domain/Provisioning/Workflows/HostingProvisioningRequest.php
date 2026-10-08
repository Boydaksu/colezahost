<?php

declare(strict_types=1);

namespace Coleza\Domain\Provisioning\Workflows;

final class HostingProvisioningRequest
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private int $serviceId,
        private ?string $domain = null,
        private ?string $username = null,
        private ?string $password = null,
        private ?string $packageIdentifier = null,
        private int $requiredDiskMb = 0,
        private int $requiredBandwidthMb = 0,
        private bool $requireDedicatedIp = false,
        private ?int $serverPoolId = null,
        private ?int $locationId = null,
        private ?int $preferredServerId = null,
        private string $providerSlug = 'cpanel',
        private ?string $contactEmail = null,
        private array $metadata = [],
        private ?string $correlationId = null
    ) {
    }

    public function getServiceId(): int
    {
        return $this->serviceId;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function getPackageIdentifier(): ?string
    {
        return $this->packageIdentifier;
    }

    public function getRequiredDiskMb(): int
    {
        return $this->requiredDiskMb;
    }

    public function getRequiredBandwidthMb(): int
    {
        return $this->requiredBandwidthMb;
    }

    public function requiresDedicatedIp(): bool
    {
        return $this->requireDedicatedIp;
    }

    public function getServerPoolId(): ?int
    {
        return $this->serverPoolId;
    }

    public function getLocationId(): ?int
    {
        return $this->locationId;
    }

    public function getPreferredServerId(): ?int
    {
        return $this->preferredServerId;
    }

    public function getProviderSlug(): string
    {
        return $this->providerSlug;
    }

    public function getContactEmail(): ?string
    {
        return $this->contactEmail;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getCorrelationId(): ?string
    {
        return $this->correlationId;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'service_id' => $this->serviceId,
            'domain' => $this->domain,
            'username' => $this->username,
            'password' => $this->password !== null ? '********' : null,
            'package_identifier' => $this->packageIdentifier,
            'required_disk_mb' => $this->requiredDiskMb,
            'required_bandwidth_mb' => $this->requiredBandwidthMb,
            'require_dedicated_ip' => $this->requireDedicatedIp,
            'server_pool_id' => $this->serverPoolId,
            'location_id' => $this->locationId,
            'preferred_server_id' => $this->preferredServerId,
            'provider_slug' => $this->providerSlug,
            'contact_email' => $this->contactEmail,
            'metadata' => $this->metadata,
            'correlation_id' => $this->correlationId,
        ];
    }
}
