<?php

declare(strict_types=1);

namespace Coleza\Foundation\Health;

use Coleza\Foundation\Storage\StorageInterface;
use Throwable;

final class StorageHealthCheck implements HealthCheckInterface
{
    public function __construct(private StorageInterface $storage)
    {
    }

    public function name(): string
    {
        return 'storage';
    }

    public function check(): HealthCheckResult
    {
        $testFile = '.health_probe_' . bin2hex(random_bytes(4)) . '.tmp';
        $testContent = 'health_check_payload';

        try {
            $this->storage->put($testFile, $testContent);
            if (!$this->storage->exists($testFile)) {
                return HealthCheckResult::unhealthy($this->name(), 'Storage write succeeded but file not found.');
            }

            $read = $this->storage->get($testFile);
            if ($read !== $testContent) {
                $this->storage->delete($testFile);
                return HealthCheckResult::unhealthy($this->name(), 'Storage read returned mismatched payload.');
            }

            $this->storage->delete($testFile);
            return HealthCheckResult::healthy($this->name(), 'Storage read/write verified.');
        } catch (Throwable $e) {
            return HealthCheckResult::unhealthy($this->name(), 'Storage probe failed: ' . $e->getMessage());
        }
    }
}
