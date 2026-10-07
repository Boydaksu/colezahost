<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Payments\Gateways\Iyzico;

final class IyzicoConfiguration
{
    public const SANDBOX_URL = 'https://sandbox-api.iyzipay.com';
    public const PRODUCTION_URL = 'https://api.iyzipay.com';

    public function __construct(
        private string $apiKey,
        private string $secretKey,
        private string $baseUrl = self::SANDBOX_URL,
        private bool $isTestMode = true
    ) {
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function getSecretKey(): string
    {
        return $this->secretKey;
    }

    public function getMaskedSecretKey(): string
    {
        if (strlen($this->secretKey) <= 6) {
            return '******';
        }

        return substr($this->secretKey, 0, 3) . '******' . substr($this->secretKey, -3);
    }

    public function getBaseUrl(): string
    {
        return rtrim($this->baseUrl, '/');
    }

    public function isTestMode(): bool
    {
        return $this->isTestMode;
    }

    /**
     * @return array<string, mixed> Redacted configuration array safe for logging
     */
    public function toSafeArray(): array
    {
        return [
            'apiKey' => $this->apiKey,
            'secretKey' => $this->getMaskedSecretKey(),
            'baseUrl' => $this->baseUrl,
            'isTestMode' => $this->isTestMode,
        ];
    }
}
