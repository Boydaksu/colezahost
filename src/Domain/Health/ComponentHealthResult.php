<?php

declare(strict_types=1);

namespace Coleza\Domain\Health;

final class ComponentHealthResult
{
    /**
     * @param string $component Identifier (e.g. 'database', 'cron', 'queue', 'storage', 'providers', 'modules', 'backup')
     * @param HealthStatus $status
     * @param string $message
     * @param array<string, mixed> $metrics
     * @param float $latencyMs
     */
    public function __construct(
        private string $component,
        private HealthStatus $status,
        private string $message,
        private array $metrics = [],
        private float $latencyMs = 0.0
    ) {
    }

    public function getComponent(): string
    {
        return $this->component;
    }

    public function getStatus(): HealthStatus
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
    public function getMetrics(): array
    {
        return $this->metrics;
    }

    public function getLatencyMs(): float
    {
        return $this->latencyMs;
    }

    public function isHealthy(): bool
    {
        return $this->status === HealthStatus::HEALTHY;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'component' => $this->component,
            'status' => $this->status->value,
            'message' => $this->message,
            'metrics' => $this->metrics,
            'latency_ms' => $this->latencyMs,
        ];
    }
}
