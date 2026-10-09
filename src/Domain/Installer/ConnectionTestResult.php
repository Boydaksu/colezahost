<?php

declare(strict_types=1);

namespace Coleza\Domain\Installer;

use JsonSerializable;

/**
 * Result of a database connection verification attempt.
 */
final class ConnectionTestResult implements JsonSerializable
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        private bool $success,
        private string $message,
        private ?string $serverVersion = null,
        private array $details = []
    ) {
    }

    public static function successful(string $serverVersion, array $details = []): self
    {
        return new self(
            success: true,
            message: 'Database connection established successfully.',
            serverVersion: $serverVersion,
            details: $details
        );
    }

    public static function failed(string $error, array $details = []): self
    {
        return new self(
            success: false,
            message: $error,
            serverVersion: null,
            details: $details
        );
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getServerVersion(): ?string
    {
        return $this->serverVersion;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'server_version' => $this->serverVersion,
            'details' => $this->details,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
