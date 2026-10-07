<?php

declare(strict_types=1);

namespace Coleza\Foundation\Health;

final class HealthCheckResult
{
    public const STATUS_HEALTHY = 'HEALTHY';
    public const STATUS_WARNING = 'WARNING';
    public const STATUS_UNHEALTHY = 'UNHEALTHY';

    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        private string $name,
        private string $status,
        private string $message = '',
        private array $meta = []
    ) {
    }

    public static function healthy(string $name, string $message = '', array $meta = []): self
    {
        return new self($name, self::STATUS_HEALTHY, $message, $meta);
    }

    public static function warning(string $name, string $message = '', array $meta = []): self
    {
        return new self($name, self::STATUS_WARNING, $message, $meta);
    }

    public static function unhealthy(string $name, string $message = '', array $meta = []): self
    {
        return new self($name, self::STATUS_UNHEALTHY, $message, $meta);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        return $this->meta;
    }

    public function isHealthy(): bool
    {
        return $this->status === self::STATUS_HEALTHY;
    }

    public function isWarning(): bool
    {
        return $this->status === self::STATUS_WARNING;
    }

    public function isUnhealthy(): bool
    {
        return $this->status === self::STATUS_UNHEALTHY;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'status' => $this->status,
            'message' => $this->message,
            'meta' => $this->meta,
        ];
    }
}
