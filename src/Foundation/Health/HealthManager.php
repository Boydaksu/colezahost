<?php

declare(strict_types=1);

namespace Coleza\Foundation\Health;

final class HealthManager
{
    /** @var array<string, HealthCheckInterface> */
    private array $checks = [];

    public function register(HealthCheckInterface $check): self
    {
        $this->checks[$check->name()] = $check;
        return $this;
    }

    /**
     * Run all registered checks and return consolidated report.
     *
     * @return array{
     *     status: string,
     *     healthy: bool,
     *     checks: array<string, array<string, mixed>>
     * }
     */
    public function report(): array
    {
        $results = [];
        $overallHealthy = true;

        foreach ($this->checks as $name => $check) {
            $result = $check->check();
            $results[$name] = $result->toArray();
            if ($result->isUnhealthy()) {
                $overallHealthy = false;
            }
        }

        return [
            'status' => $overallHealthy ? 'OK' : 'DEGRADED',
            'healthy' => $overallHealthy,
            'checks' => $results,
        ];
    }
}
