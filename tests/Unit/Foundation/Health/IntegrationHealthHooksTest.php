<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Foundation\Health;

use Coleza\Domain\Commerce\Payments\Gateways\Iyzico\IyzicoConfiguration;
use Coleza\Domain\Notifications\Transport\MemoryMailTransport;
use Coleza\Domain\Notifications\Transport\SmtpConfiguration;
use Coleza\Domain\Webhooks\WebhookService;
use Coleza\Foundation\Health\HealthManager;
use Coleza\Domain\Integrations\Health\IntegrationHealthRegistry;
use Coleza\Domain\Integrations\Health\PaymentGatewayHealthCheck;
use Coleza\Domain\Integrations\Health\SmtpHealthCheck;
use Coleza\Domain\Integrations\Health\WebhookQueueHealthCheck;
use PDO;
use PHPUnit\Framework\TestCase;

final class IntegrationHealthHooksTest extends TestCase
{
    public function testSmtpHealthCheckWithInMemoryTransport(): void
    {
        $transport = new MemoryMailTransport();
        $check = new SmtpHealthCheck($transport);

        $result = $check->check();

        $this->assertSame('integration.smtp', $check->name());
        $this->assertTrue($result->isHealthy());
        $this->assertSame('in_memory', $result->getMeta()['transport']);
    }

    public function testSmtpHealthCheckWithReachableSocket(): void
    {
        $config = new SmtpConfiguration(
            host: 'mail.coleza.test',
            port: 587,
            username: 'notifications@coleza.test',
            password: 'secretPassword123'
        );

        $mockProbe = fn(string $host, int $port, float $timeout) => true;

        $check = new SmtpHealthCheck(null, $config, $mockProbe);
        $result = $check->check();

        $this->assertTrue($result->isHealthy());
        $this->assertStringContainsString('reachable', $result->getMessage());
        $this->assertSame('mail.coleza.test', $result->getMeta()['host']);
        // Crucial security invariant: Password must NOT appear anywhere in health meta
        $this->assertArrayNotHasKey('password', $result->getMeta());
    }

    public function testSmtpHealthCheckWithUnreachableSocket(): void
    {
        $config = new SmtpConfiguration(
            host: 'unreachable.mail.server',
            port: 25,
            username: 'test',
            password: 'pwd'
        );

        $mockProbe = fn(string $host, int $port, float $timeout) => false;

        $check = new SmtpHealthCheck(null, $config, $mockProbe);
        $result = $check->check();

        $this->assertTrue($result->isUnhealthy());
        $this->assertStringContainsString('Cannot connect', $result->getMessage());
    }

    public function testPaymentGatewayHealthCheckMissingCredentials(): void
    {
        $config = new IyzicoConfiguration(
            apiKey: '',
            secretKey: '',
            baseUrl: 'https://api.iyzipay.com'
        );

        $check = new PaymentGatewayHealthCheck(null, $config);
        $result = $check->check();

        $this->assertTrue($result->isUnhealthy());
        $this->assertStringContainsString('credentials are not configured', $result->getMessage());
    }

    public function testPaymentGatewayHealthCheckTestModeWarning(): void
    {
        $config = new IyzicoConfiguration(
            apiKey: 'sandbox_key_123',
            secretKey: 'sandbox_secret_456',
            baseUrl: 'https://sandbox-api.iyzipay.com',
            isTestMode: true
        );

        $mockPing = fn(string $url) => true;

        $check = new PaymentGatewayHealthCheck(null, $config, $mockPing);
        $result = $check->check();

        $this->assertTrue($result->isWarning());
        $this->assertStringContainsString('sandbox/test mode', $result->getMessage());
        $this->assertTrue($result->getMeta()['is_test_mode']);
        // Masked key in meta, raw key hidden
        $this->assertSame('sand***', $result->getMeta()['masked_api_key']);
    }

    public function testPaymentGatewayHealthCheckLiveHealthy(): void
    {
        $config = new IyzicoConfiguration(
            apiKey: 'live_key_999',
            secretKey: 'live_secret_888',
            baseUrl: 'https://api.iyzipay.com',
            isTestMode: false
        );

        $mockPing = fn(string $url) => true;

        $check = new PaymentGatewayHealthCheck(null, $config, $mockPing);
        $result = $check->check();

        $this->assertTrue($result->isHealthy());
        $this->assertFalse($result->getMeta()['is_test_mode']);
    }

    public function testWebhookQueueHealthCheckThresholds(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $webhookService = new WebhookService($pdo);

        $check = new WebhookQueueHealthCheck($webhookService, $pdo, warningPendingThreshold: 10, unhealthyPendingThreshold: 50);

        // 1. Initial empty queue -> HEALTHY
        $res1 = $check->check();
        $this->assertTrue($res1->isHealthy());
        $this->assertSame(0, $res1->getMeta()['pending_deliveries']);

        // 2. Insert 15 pending retries (is_success = 0, next_retry_at set, attempt_number < max_attempts) -> WARNING
        for ($i = 0; $i < 15; $i++) {
            $pdo->exec("INSERT INTO webhook_deliveries (endpoint_id, event_id, event_type, payload, attempt_number, is_success, next_retry_at, max_attempts, created_at)
                        VALUES (1, 'ev_{$i}', 'order.created', '{}', 1, 0, '2026-10-08 12:00:00', 5, '2026-10-08 10:00:00')");
        }

        $res2 = $check->check();
        $this->assertTrue($res2->isWarning());
        $this->assertStringContainsString('backlog elevated', $res2->getMessage());

        // 3. Insert 40 more (total 55) -> UNHEALTHY
        for ($i = 15; $i < 55; $i++) {
            $pdo->exec("INSERT INTO webhook_deliveries (endpoint_id, event_id, event_type, payload, attempt_number, is_success, next_retry_at, max_attempts, created_at)
                        VALUES (1, 'ev_{$i}', 'order.created', '{}', 1, 0, '2026-10-08 12:00:00', 5, '2026-10-08 10:00:00')");
        }

        $res3 = $check->check();
        $this->assertTrue($res3->isUnhealthy());
        $this->assertStringContainsString('backlog is critical', $res3->getMessage());
    }

    public function testIntegrationHealthRegistryConsolidatedReport(): void
    {
        $healthManager = new HealthManager();
        $registry = new IntegrationHealthRegistry($healthManager);

        $mailTransport = new MemoryMailTransport();
        $iyzicoConfig = new IyzicoConfiguration('live_k', 'live_s', 'https://api.iyzipay.com', false);
        $mockPing = fn($u) => true;

        $registry->registerAll(
            mailTransport: $mailTransport,
            iyzicoConfig: $iyzicoConfig,
            gatewayProbe: $mockPing
        );

        $report = $registry->report();

        $this->assertSame('OK', $report['status']);
        $this->assertTrue($report['healthy']);
        $this->assertArrayHasKey('integration.smtp', $report['checks']);
        $this->assertArrayHasKey('integration.gateway.payment_gateway', $report['checks']);
        $this->assertArrayHasKey('integration.webhook.queue', $report['checks']);
    }
}
