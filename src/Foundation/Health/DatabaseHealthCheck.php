<?php

declare(strict_types=1);

namespace Coleza\Foundation\Health;

use Coleza\Foundation\Database\Connection;
use Throwable;

final class DatabaseHealthCheck implements HealthCheckInterface
{
    public function __construct(private Connection $db)
    {
    }

    public function name(): string
    {
        return 'database';
    }

    public function check(): HealthCheckResult
    {
        try {
            $row = $this->db->selectOne('SELECT 1 as alive');
            if ($row !== null && isset($row['alive'])) {
                return HealthCheckResult::healthy($this->name(), 'Database connection active.', [
                    'driver' => $this->db->getDriverName(),
                ]);
            }

            return HealthCheckResult::unhealthy($this->name(), 'Database query returned unexpected result.');
        } catch (Throwable $e) {
            return HealthCheckResult::unhealthy($this->name(), 'Database probe failed: ' . $e->getMessage());
        }
    }
}
