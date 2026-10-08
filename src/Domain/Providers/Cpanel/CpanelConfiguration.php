<?php

declare(strict_types=1);

namespace Coleza\Domain\Providers\Cpanel;

use Coleza\Domain\Providers\DTO\ServerConnectionDto;
use InvalidArgumentException;

final class CpanelConfiguration
{
    public function __construct(
        private string $hostname,
        private string $username = 'root',
        private ?string $apiToken = null,
        private ?string $accessHash = null,
        private ?string $password = null,
        private int $port = 2087,
        private bool $useSsl = true,
        private int $timeoutSeconds = 30
    ) {
        $cleanHost = trim($this->hostname);
        if ($cleanHost === '') {
            throw new InvalidArgumentException('cPanel hostname cannot be empty.');
        }
        $this->hostname = $cleanHost;

        $cleanUser = trim($this->username);
        if ($cleanUser === '') {
            throw new InvalidArgumentException('cPanel username cannot be empty.');
        }
        $this->username = $cleanUser;

        if ($this->port <= 0 || $this->port > 65535) {
            throw new InvalidArgumentException("Invalid port number {$this->port}.");
        }

        if ($this->apiToken === null && $this->accessHash === null && $this->password === null) {
            throw new InvalidArgumentException('At least one authentication credential (apiToken, accessHash, or password) must be provided.');
        }
    }

    public static function fromServerConnection(ServerConnectionDto $conn): self
    {
        $options = $conn->getOptions();
        $username = (string)($options['whm_username'] ?? $options['username'] ?? 'root');
        $port = $conn->getPort() ?? ($conn->isSecure() ? 2087 : 2086);
        $authType = $conn->getAuthType();
        $secret = $conn->getAuthSecret();

        $apiToken = null;
        $accessHash = null;
        $password = null;

        if ($authType === 'access_hash') {
            $accessHash = $secret;
        } elseif ($authType === 'password') {
            $password = $secret;
        } else {
            // Default to API Token
            $apiToken = $secret;
        }

        $timeout = isset($options['timeout_seconds']) ? max(5, (int)$options['timeout_seconds']) : 30;

        return new self(
            hostname: $conn->getHostname(),
            username: $username,
            apiToken: $apiToken,
            accessHash: $accessHash,
            password: $password,
            port: $port,
            useSsl: $conn->isSecure(),
            timeoutSeconds: $timeout
        );
    }

    public function getHostname(): string
    {
        return $this->hostname;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getApiToken(): ?string
    {
        return $this->apiToken;
    }

    public function getAccessHash(): ?string
    {
        return $this->accessHash;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function isUseSsl(): bool
    {
        return $this->useSsl;
    }

    public function getTimeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function getBaseUrl(): string
    {
        $scheme = $this->useSsl ? 'https' : 'http';
        return "{$scheme}://{$this->hostname}:{$this->port}";
    }

    public function getAuthType(): string
    {
        if ($this->apiToken !== null) {
            return 'api_token';
        }
        if ($this->accessHash !== null) {
            return 'access_hash';
        }
        return 'password';
    }

    public function getMaskedSecret(): string
    {
        $secret = $this->apiToken ?? $this->accessHash ?? $this->password ?? '';
        if ($secret === '') {
            return '[NOT_SET]';
        }
        $len = strlen($secret);
        if ($len <= 4) {
            return '••••';
        }
        return substr($secret, 0, 2) . str_repeat('•', min(8, $len - 4)) . substr($secret, -2);
    }

    /**
     * @return array<string, mixed>
     */
    public function toSafeArray(): array
    {
        return [
            'hostname' => $this->hostname,
            'username' => $this->username,
            'port' => $this->port,
            'use_ssl' => $this->useSsl,
            'timeout_seconds' => $this->timeoutSeconds,
            'auth_type' => $this->getAuthType(),
            'auth_secret' => $this->getMaskedSecret(),
            'base_url' => $this->getBaseUrl(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return $this->toSafeArray();
    }
}
