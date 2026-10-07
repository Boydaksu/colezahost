<?php

declare(strict_types=1);

namespace Coleza\Domain\Integrations\Health;

use Coleza\Domain\Notifications\Transport\MailerInterface;
use Coleza\Domain\Notifications\Transport\MemoryMailTransport;
use Coleza\Domain\Notifications\Transport\SmtpConfiguration;
use Coleza\Domain\Notifications\Transport\SmtpTransport;
use Coleza\Foundation\Health\HealthCheckInterface;
use Coleza\Foundation\Health\HealthCheckResult;
use Throwable;

final class SmtpHealthCheck implements HealthCheckInterface
{
    /**
     * @param callable|null $socketProbe Optional socket probe callable: fn(string $host, int $port, float $timeout): bool
     */
    public function __construct(
        private ?MailerInterface $transport = null,
        private ?SmtpConfiguration $config = null,
        private mixed $socketProbe = null
    ) {
    }

    public function name(): string
    {
        return 'integration.smtp';
    }

    public function check(): HealthCheckResult
    {
        // 1. If in-memory transport is used (test/dev environment)
        if ($this->transport instanceof MemoryMailTransport) {
            return HealthCheckResult::healthy($this->name(), 'In-memory test mail transport active.', [
                'transport' => 'in_memory',
                'environment' => 'testing',
            ]);
        }

        // 2. Resolve configuration
        $config = $this->config;
        if ($config === null && $this->transport instanceof SmtpTransport) {
            $config = $this->transport->getConfiguration();
        }

        if ($config === null) {
            return HealthCheckResult::unhealthy($this->name(), 'SMTP configuration is missing.', [
                'error' => 'NO_CONFIGURATION',
            ]);
        }

        $host = trim($config->getHost());
        $port = $config->getPort();

        if ($host === '' || $port <= 0) {
            return HealthCheckResult::unhealthy($this->name(), 'Invalid SMTP host or port configuration.', [
                'host' => $host,
                'port' => $port,
            ]);
        }

        // 3. Perform network probe
        try {
            $isReachable = false;
            if (is_callable($this->socketProbe)) {
                $isReachable = (bool) ($this->socketProbe)($host, $port, 2.0);
            } else {
                $fp = @fsockopen($host, $port, $errno, $errstr, 2.0);
                if ($fp) {
                    $isReachable = true;
                    fclose($fp);
                }
            }

            if (!$isReachable) {
                return HealthCheckResult::unhealthy($this->name(), "Cannot connect to SMTP server at {$host}:{$port}.", [
                    'host' => $host,
                    'port' => $port,
                    'encryption' => $config->getEncryption(),
                ]);
            }

            return HealthCheckResult::healthy($this->name(), "SMTP server at {$host}:{$port} is reachable.", [
                'host' => $host,
                'port' => $port,
                'encryption' => $config->getEncryption(),
                'from_email' => $config->getFromEmail(),
            ]);
        } catch (Throwable $e) {
            return HealthCheckResult::unhealthy($this->name(), 'SMTP reachability probe failed: ' . $e->getMessage(), [
                'host' => $host,
                'port' => $port,
            ]);
        }
    }
}
