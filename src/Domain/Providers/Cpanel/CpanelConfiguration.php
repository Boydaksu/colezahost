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
        $host = trim($this->hostname);
        $ip = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if (!$ip && (strlen($host) > 253 || !preg_match('/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/D', $host))) {
            throw new InvalidArgumentException('cPanel hostname must be a plain DNS name or IP address.');
        }
        if (!preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,63}$/D', $this->username)) {
            throw new InvalidArgumentException('Invalid cPanel username.');
        }
        if (!$useSsl || $port < 1 || $port > 65535 || $timeoutSeconds < 1 || $timeoutSeconds > 120) {
            throw new InvalidArgumentException('cPanel requires HTTPS, a valid port and a bounded timeout.');
        }
        $credentials = array_filter([$apiToken, $accessHash, $password], static fn($value) => $value !== null);
        if (count($credentials) !== 1) {
            throw new InvalidArgumentException('Exactly one cPanel authentication credential is required.');
        }
        $credential = $apiToken ?? $accessHash ?? $password;
        if (trim($credential) === '' || strlen($credential) > 65536 || ($accessHash === null && preg_match('/[\x00-\x1f\x7f]/', $credential))) {
            throw new InvalidArgumentException('Invalid or empty cPanel credential.');
        }
        $this->hostname = strtolower($host);
    }

    public static function fromServerConnection(ServerConnectionDto $conn): self
    {
        $options = $conn->getOptions();
        $username = $options['whm_username'] ?? $options['username'] ?? 'root';
        if (!is_string($username)) {
            throw new InvalidArgumentException('Invalid cPanel username option.');
        }
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
        } elseif ($authType === 'api_token') {
            $apiToken = $secret;
        } else {
            throw new InvalidArgumentException('Unsupported cPanel authentication type.');
        }

        $timeout = $options['timeout_seconds'] ?? 30;
        if (!is_int($timeout) && !(is_string($timeout) && ctype_digit($timeout))) {
            throw new InvalidArgumentException('Invalid cPanel timeout option.');
        }
        $timeout = (int)$timeout;

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
        $host = str_contains($this->hostname, ':') ? '[' . $this->hostname . ']' : $this->hostname;
        return "{$scheme}://{$host}:{$this->port}";
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
