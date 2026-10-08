<?php

declare(strict_types=1);

namespace Coleza\Domain\Providers\Cpanel;

use Coleza\Domain\Servers\Entities\Server;
use Coleza\Domain\Servers\Placement\ServerHealthCheckerInterface;
use Coleza\Domain\Servers\Placement\ServerHealthResult;
use Throwable;

final class CpanelServerHealthChecker implements ServerHealthCheckerInterface
{
    public function __construct(
        private ?CpanelHttpTransportInterface $transport = null,
        private float $maxLoadThreshold = 25.0,
        private int $maxLatencyMs = 5000
    ) {
    }

    public function checkHealth(Server $server): ServerHealthResult
    {
        // Ignore servers not belonging to cPanel
        if (strtolower($server->getProviderSlug()) !== 'cpanel') {
            return ServerHealthResult::healthy('Non-cPanel server skipped by cPanel health checker.');
        }

        try {
            $conn = $server->toServerConnectionDto();
            $config = CpanelConfiguration::fromServerConnection($conn);
            $client = new CpanelApiClient($config, $this->transport);

            $authResult = $client->testAuthentication();
            $version = $authResult['version'];
            $latency = $authResult['latency_ms'];

            $loadResult = $client->getSystemLoad();
            $load1m = $loadResult['1m'];
            $load5m = $loadResult['5m'];
            $load15m = $loadResult['15m'];

            $details = [
                'provider' => 'cpanel',
                'hostname' => $server->getHostname(),
                'version' => $version,
                'load_1m' => $load1m,
                'load_5m' => $load5m,
                'load_15m' => $load15m,
                'latency_ms' => $latency,
            ];

            if ($load1m > $this->maxLoadThreshold) {
                return ServerHealthResult::unhealthy(
                    message: sprintf(
                        "cPanel server '%s' has critical load average (%.2f > %.2f threshold).",
                        $server->getHostname(),
                        $load1m,
                        $this->maxLoadThreshold
                    ),
                    responseTimeMs: $latency,
                    details: $details
                );
            }

            if ($latency > $this->maxLatencyMs) {
                return ServerHealthResult::unhealthy(
                    message: sprintf(
                        "cPanel server '%s' latency (%d ms) exceeds threshold (%d ms).",
                        $server->getHostname(),
                        $latency,
                        $this->maxLatencyMs
                    ),
                    responseTimeMs: $latency,
                    details: $details
                );
            }

            return ServerHealthResult::healthy(
                message: sprintf("cPanel server '%s' operational (v%s, load: %.2f)", $server->getHostname(), $version, $load1m),
                responseTimeMs: $latency,
                details: $details
            );
        } catch (Throwable $e) {
            return ServerHealthResult::unhealthy(
                message: "cPanel health check failed for '{$server->getHostname()}': {$e->getMessage()}",
                responseTimeMs: null,
                details: [
                    'provider' => 'cpanel',
                    'hostname' => $server->getHostname(),
                    'error' => $e->getMessage(),
                    'error_class' => get_class($e),
                ]
            );
        }
    }
}
