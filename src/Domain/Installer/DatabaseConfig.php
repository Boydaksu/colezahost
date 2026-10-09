<?php

declare(strict_types=1);

namespace Coleza\Domain\Installer;

use JsonSerializable;

/**
 * Value object representing database connection parameters for installation.
 */
final class DatabaseConfig implements JsonSerializable
{
    public function __construct(
        private string $driver = 'mysql',
        private string $host = '127.0.0.1',
        private int $port = 3306,
        private string $database = 'colezahost',
        private string $username = 'root',
        private string $password = '',
        private string $prefix = '',
        private string $charset = 'utf8mb4',
        private string $collation = 'utf8mb4_unicode_ci'
    ) {
    }

    public static function sqlite(string $path = ':memory:'): self
    {
        return new self(
            driver: 'sqlite',
            database: $path
        );
    }

    public function getDriver(): string
    {
        return strtolower(trim($this->driver));
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getDatabase(): string
    {
        return $this->database;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function getCharset(): string
    {
        return $this->charset;
    }

    public function getCollation(): string
    {
        return $this->collation;
    }

    public function buildDsn(): string
    {
        if ($this->getDriver() === 'sqlite') {
            return 'sqlite:' . $this->database;
        }

        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->host,
            $this->port,
            $this->database,
            $this->charset
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $hidePassword = true): array
    {
        return [
            'driver' => $this->driver,
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'password' => $hidePassword ? '******' : $this->password,
            'prefix' => $this->prefix,
            'charset' => $this->charset,
            'collation' => $this->collation,
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
