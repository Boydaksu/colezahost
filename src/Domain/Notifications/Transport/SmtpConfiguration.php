<?php

declare(strict_types=1);

namespace Coleza\Domain\Notifications\Transport;

final class SmtpConfiguration
{
    public function __construct(
        private string $host = '127.0.0.1',
        private int $port = 587,
        private ?string $username = null,
        private ?string $password = null,
        private string $encryption = 'tls', // 'tls', 'ssl', 'none'
        private string $fromEmail = 'noreply@coleza.com',
        private string $fromName = 'Coleza Host',
        private int $timeoutSeconds = 15
    ) {
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function getMaskedPassword(): string
    {
        if ($this->password === null || $this->password === '') {
            return '';
        }

        return str_repeat('*', 8);
    }

    public function getEncryption(): string
    {
        return strtolower($this->encryption);
    }

    public function getFromEmail(): string
    {
        return $this->fromEmail;
    }

    public function getFromName(): string
    {
        return $this->fromName;
    }

    public function getTimeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    /**
     * @return array<string, mixed> Redacted configuration array safe for logging
     */
    public function toSafeArray(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'password' => $this->getMaskedPassword(),
            'encryption' => $this->encryption,
            'fromEmail' => $this->fromEmail,
            'fromName' => $this->fromName,
            'timeoutSeconds' => $this->timeoutSeconds,
        ];
    }
}
