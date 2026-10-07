<?php

declare(strict_types=1);

namespace Coleza\Foundation\Health;

interface HealthCheckInterface
{
    /**
     * Unique identifier for this health check.
     */
    public function name(): string;

    /**
     * Run the health inspection and return result.
     */
    public function check(): HealthCheckResult;
}
