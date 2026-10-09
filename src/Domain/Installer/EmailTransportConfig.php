<?php

declare(strict_types=1);

namespace Coleza\Domain\Installer;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Value object representing outbound email transport settings.
 */
final class EmailTransportConfig implements JsonSerializable
{
    private const ALLOWED_DRIVERS = ['smtp', 'sendmail', 'memory', 'log'];

    public function __construct(
        private string $driver = 'smtp',
        private string $fromAddress = 'noreply@colezahost.com',
        private string $fromName = 'Coleza Host',
        private ?string $host = null,
        private ?int $port = 587,
        private ?string $encryption = 'tls',
        private ?string $username = null,
        private ?string $password = null
    ) {
        $this->driver = strtolower(trim($this->driver));
        $this->fromAddress = strtolower(trim($this->fromAddress));
        $this->fromName = trim($this->fromName);

        $this->validate();
    }

    private function validate(): void
    {
        if (!in_array($this->driver, self::ALLOWED_DRIVERS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid email driver [%s]. Allowed drivers: %s.',
                $this->driver,
                implode(', ', self::ALLOWED_DRIVERS)
            ));
        }

        if ($this->fromAddress === '' || !filter_var($this->fromAddress, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException(sprintf('Invalid sender email address [%s].', $this->fromAddress));
        }

        if ($this->driver === 'smtp' && ($this->host === null || trim($this->host) === '')) {
            throw new InvalidArgumentException('SMTP host is required when driver is smtp.');
        }
    }

    public static function memory(string $fromAddress = 'noreply@example.com', string $fromName = 'Coleza'): self
    {
        return new self(
            driver: 'memory',
            fromAddress: $fromAddress,
            fromName: $fromName
        );
    }

    public function getDriver(): string
    {
        return $this->driver;
    }

    public function getFromAddress(): string
    {
        return $this->fromAddress;
    }

    public function getFromName(): string
    {
        return $this->fromName;
    }

    public function getHost(): ?string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function getEncryption(): ?string
    {
        return $this->encryption;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $hidePassword = true): array
    {
        return [
            'driver' => $this->driver,
            'from_address' => $this->fromAddress,
            'from_name' => $this->fromName,
            'host' => $this->host,
            'port' => $this->port,
            'encryption' => $this->encryption,
            'username' => $this->username,
            'password' => $hidePassword ? '******' : $this->password,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray(true);
    }
}
