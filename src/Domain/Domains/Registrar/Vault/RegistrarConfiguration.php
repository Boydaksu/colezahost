<?php

declare(strict_types=1);

namespace Coleza\Domain\Domains\Registrar\Vault;

final class RegistrarConfiguration
{
    /**
     * @param array<string, mixed> $customSettings
     */
    public function __construct(
        private readonly string $registrarId,
        private readonly string $apiKey,
        private readonly ?string $apiSecret = null,
        private readonly ?string $resellerId = null,
        private readonly ?string $endpoint = null,
        private readonly bool $isSandbox = false,
        private readonly array $customSettings = []
    ) {
    }

    public function getRegistrarId(): string
    {
        return $this->registrarId;
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function getApiSecret(): ?string
    {
        return $this->apiSecret;
    }

    public function getResellerId(): ?string
    {
        return $this->resellerId;
    }

    public function getEndpoint(): ?string
    {
        return $this->endpoint;
    }

    public function isSandbox(): bool
    {
        return $this->isSandbox;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCustomSettings(): array
    {
        return $this->customSettings;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $maskSecrets = true): array
    {
        return [
            'registrar_id' => $this->registrarId,
            'api_key' => $maskSecrets ? '••••••••' : $this->apiKey,
            'api_secret' => ($this->apiSecret !== null) ? ($maskSecrets ? '••••••••' : $this->apiSecret) : null,
            'reseller_id' => $this->resellerId,
            'endpoint' => $this->endpoint,
            'is_sandbox' => $this->isSandbox,
            'custom_settings' => $this->customSettings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return $this->toArray(maskSecrets: true);
    }
}
