<?php

declare(strict_types=1);

namespace Coleza\Domain\Integrations\Health;

use Coleza\Domain\Commerce\Payments\Gateways\Iyzico\IyzicoConfiguration;
use Coleza\Domain\Commerce\Payments\Gateways\PaymentGatewayInterface;
use Coleza\Foundation\Health\HealthCheckInterface;
use Coleza\Foundation\Health\HealthCheckResult;
use Throwable;

final class PaymentGatewayHealthCheck implements HealthCheckInterface
{
    /**
     * @param callable|null $pingProbe Optional ping probe callable: fn(string $url): bool
     */
    public function __construct(
        private ?PaymentGatewayInterface $gateway = null,
        private ?IyzicoConfiguration $iyzicoConfig = null,
        private mixed $pingProbe = null
    ) {
    }

    public function name(): string
    {
        $gatewayId = $this->gateway?->getIdentifier() ?? 'payment_gateway';
        return "integration.gateway.{$gatewayId}";
    }

    public function check(): HealthCheckResult
    {
        $config = $this->iyzicoConfig;
        if ($config === null) {
            return HealthCheckResult::unhealthy($this->name(), 'Payment gateway configuration is missing.', [
                'error' => 'NO_CONFIG',
            ]);
        }

        $apiKey = trim($config->getApiKey());
        $secretKey = trim($config->getSecretKey());
        $baseUrl = trim($config->getBaseUrl());

        if ($apiKey === '' || $secretKey === '') {
            return HealthCheckResult::unhealthy($this->name(), 'Gateway API credentials are not configured.', [
                'has_api_key' => $apiKey !== '',
                'has_secret_key' => $secretKey !== '',
            ]);
        }

        // Check reachability
        try {
            $isReachable = false;
            if (is_callable($this->pingProbe)) {
                $isReachable = (bool) ($this->pingProbe)($baseUrl);
            } else {
                $parsed = parse_url($baseUrl);
                $host = $parsed['host'] ?? '';
                $port = (int) ($parsed['port'] ?? (($parsed['scheme'] ?? 'https') === 'https' ? 443 : 80));

                if ($host !== '') {
                    $fp = @fsockopen(($port === 443 ? 'ssl://' : '') . $host, $port, $errno, $errstr, 2.0);
                    if ($fp) {
                        $isReachable = true;
                        fclose($fp);
                    }
                }
            }

            if (!$isReachable) {
                return HealthCheckResult::unhealthy($this->name(), "Payment gateway endpoint at {$baseUrl} is unreachable.", [
                    'base_url' => $baseUrl,
                    'masked_key' => substr($apiKey, 0, 4) . '***',
                    'is_test_mode' => $config->isTestMode(),
                ]);
            }

            $meta = [
                'base_url' => $baseUrl,
                'masked_api_key' => substr($apiKey, 0, 4) . '***',
                'is_test_mode' => $config->isTestMode(),
            ];

            if ($config->isTestMode()) {
                return HealthCheckResult::warning(
                    $this->name(),
                    'Payment gateway is operating in sandbox/test mode.',
                    $meta
                );
            }

            return HealthCheckResult::healthy(
                $this->name(),
                'Payment gateway is configured and reachable.',
                $meta
            );
        } catch (Throwable $e) {
            return HealthCheckResult::unhealthy($this->name(), 'Gateway health probe error: ' . $e->getMessage());
        }
    }
}
