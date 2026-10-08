<?php

declare(strict_types=1);

namespace Coleza\Domain\Servers\Placement;

final class ServerHealthResult
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        private bool $healthy,
        private string $message,
        private ?int $responseTimeMs = null,
        private array $details = []
    ) {
    }

    public static function healthy(string $message = 'Server is operational', ?int $responseTimeMs = null, array $details = []): self
    {
        return new self(true, $message, $responseTimeMs, $details);
    }

    public static function unhealthy(string $message, ?int $responseTimeMs = null, array $details = []): self
    {
        return new self(false, $message, $responseTimeMs, $details);
    }

    public function isHealthy(): bool
    {
        return $this->healthy;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getResponseTimeMs(): ?int
    {
        return $this->responseTimeMs;
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
            'healthy' => $this->healthy,
            'message' => $this->message,
            'response_time_ms' => $this->responseTimeMs,
            'details' => $this->details,
        ];
    }
}
