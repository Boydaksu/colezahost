<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Adoption;

final class ProviderIdentityResolver
{
    /**
     * @param array<string, int> $serverMap Mapping source server ID/hostname to Coleza server ID
     * @param array<string, string> $registrarMap Mapping source registrar code to Coleza registrar provider
     * @param array<string, int> $productMap Mapping source product ID to Coleza product ID
     * @param array<string, int> $clientMap Mapping source client ID to Coleza user ID
     */
    public function __construct(
        private array $serverMap = [],
        private array $registrarMap = [],
        private array $productMap = [],
        private array $clientMap = []
    ) {
    }

    public function registerServerMapping(string $sourceServerKey, int $targetServerId): void
    {
        $this->serverMap[strtolower(trim($sourceServerKey))] = $targetServerId;
    }

    public function registerRegistrarMapping(string $sourceRegistrarKey, string $targetRegistrar): void
    {
        $this->registrarMap[strtolower(trim($sourceRegistrarKey))] = strtolower(trim($targetRegistrar));
    }

    public function registerProductMapping(string $sourceProductKey, int $targetProductId): void
    {
        $this->productMap[(string)$sourceProductKey] = $targetProductId;
    }

    public function registerClientMapping(string $sourceClientKey, int $targetUserId): void
    {
        $this->clientMap[(string)$sourceClientKey] = $targetUserId;
    }

    public function resolveServer(string $sourceServerKey, ?int $defaultServerId = null): ?int
    {
        $key = strtolower(trim($sourceServerKey));
        return $this->serverMap[$key] ?? $defaultServerId;
    }

    public function resolveRegistrar(string $sourceRegistrarKey, ?string $defaultRegistrar = null): ?string
    {
        $key = strtolower(trim($sourceRegistrarKey));
        return $this->registrarMap[$key] ?? $defaultRegistrar;
    }

    public function resolveProduct(string $sourceProductKey, ?int $defaultProductId = null): ?int
    {
        $key = (string) $sourceProductKey;
        return $this->productMap[$key] ?? $defaultProductId;
    }

    public function resolveClient(string $sourceClientKey): ?int
    {
        $key = (string) $sourceClientKey;
        return $this->clientMap[$key] ?? null;
    }
}
