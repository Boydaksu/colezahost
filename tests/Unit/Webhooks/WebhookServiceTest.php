<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Webhooks;

use Coleza\Domain\Webhooks\WebhookService;
use Coleza\Domain\Webhooks\WebhookSigner;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class WebhookServiceTest extends TestCase
{
    private PDO $pdo;
    private WebhookService $service;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->service = new WebhookService($this->pdo);
    }

    public function testRegisterEndpointGeneratesSecureSecret(): void
    {
        $endpoint = $this->service->registerEndpoint(
            targetUrl: 'https://example.com/api/webhooks/coleza',
            events: ['order.created', 'invoice.paid'],
            organizationId: 1,
            description: 'ERP integration'
        );

        $this->assertNotNull($endpoint->getId());
        $this->assertSame('https://example.com/api/webhooks/coleza', $endpoint->getTargetUrl());
        $this->assertStringStartsWith('whsec_', $endpoint->getSecret());
        $this->assertTrue($endpoint->isActive());
        $this->assertTrue($endpoint->matchesEvent('order.created'));
        $this->assertTrue($endpoint->matchesEvent('invoice.paid'));
        $this->assertFalse($endpoint->matchesEvent('service.suspended'));

        // Invalid URL validation
        $this->expectException(ValidationException::class);
        $this->service->registerEndpoint('invalid-not-a-url');
    }

    public function testWebhookSignerHmacAndReplayProtection(): void
    {
        $payload = json_encode(['event' => 'invoice.paid', 'amount' => 15000]);
        $secret = 'whsec_test_secret_key_12345';
        $now = time();

        $signatureHeader = WebhookSigner::sign($payload, $secret, $now);

        $this->assertStringStartsWith("t={$now},v1=", $signatureHeader);

        // 1. Valid signature check
        $this->assertTrue(WebhookSigner::verify($payload, $signatureHeader, $secret, 300));

        // 2. Tampered payload check
        $tamperedPayload = json_encode(['event' => 'invoice.paid', 'amount' => 99999]);
        $this->assertFalse(WebhookSigner::verify($tamperedPayload, $signatureHeader, $secret, 300));

        // 3. Wrong secret check
        $this->assertFalse(WebhookSigner::verify($payload, $signatureHeader, 'whsec_wrong_secret', 300));

        // 4. Replay attack check (timestamp beyond tolerance window)
        $oldTimestamp = time() - 600;
        $oldSignature = WebhookSigner::sign($payload, $secret, $oldTimestamp);
        $this->assertFalse(WebhookSigner::verify($payload, $oldSignature, $secret, 300));
    }

    public function testDispatchAndSuccessfulDelivery(): void
    {
        $endpoint = $this->service->registerEndpoint(
            targetUrl: 'https://webhook.site/test',
            events: ['order.created']
        );

        $deliveries = $this->service->dispatch('order.created', [
            'order_id' => 105,
            'order_number' => 'ORD-20261007-000105',
        ]);

        $this->assertCount(1, $deliveries);
        $delivery = $deliveries[0];
        $this->assertSame($endpoint->getId(), $delivery->getEndpointId());
        $this->assertFalse($delivery->isSuccess());

        // Mock HTTP client returning 200 OK
        $mockHttpClient = function (string $url, string $payload, array $headers): array {
            return ['code' => 200, 'body' => '{"received": true}'];
        };

        $executed = $this->service->deliver($delivery, $mockHttpClient);

        $this->assertTrue($executed->isSuccess());
        $this->assertSame(200, $executed->getStatusCode());
        $this->assertNull($executed->getNextRetryAt());
        $this->assertNotNull($executed->getCompletedAt());
    }

    public function testFailedDeliverySchedulesExponentialBackoffRetry(): void
    {
        $endpoint = $this->service->registerEndpoint(
            targetUrl: 'https://webhook.site/failing',
            events: ['*']
        );

        $deliveries = $this->service->dispatch('service.suspended', ['service_id' => 88]);
        $delivery = $deliveries[0];

        // Mock HTTP client returning 500 Internal Server Error
        $mockHttpClient = function (string $url, string $payload, array $headers): array {
            return ['code' => 500, 'body' => 'Internal server error'];
        };

        $executed = $this->service->deliver($delivery, $mockHttpClient);

        $this->assertFalse($executed->isSuccess());
        $this->assertSame(500, $executed->getStatusCode());
        $this->assertNotNull($executed->getNextRetryAt());
        $this->assertSame(1, $executed->getAttemptNumber());
        $this->assertTrue($executed->canRetry());

        // Delay for 1st attempt backoff is 60 seconds
        $diff = strtotime($executed->getNextRetryAt()) - time();
        $this->assertGreaterThanOrEqual(58, $diff);
        $this->assertLessThanOrEqual(62, $diff);
    }

    public function testManualRedeliveryAndWorkerRetry(): void
    {
        $endpoint = $this->service->registerEndpoint(
            targetUrl: 'https://webhook.site/retry',
            events: ['*']
        );

        $deliveries = $this->service->dispatch('invoice.paid', ['invoice_id' => 42]);
        $delivery = $deliveries[0];

        // 1. Initial attempt fails
        $failingClient = fn() => ['code' => 503, 'body' => 'Service Unavailable'];
        $failed = $this->service->deliver($delivery, $failingClient);
        $this->assertFalse($failed->isSuccess());
        $this->assertSame(1, $failed->getAttemptNumber());

        // 2. Manual redelivery succeeds
        $successClient = fn() => ['code' => 200, 'body' => 'OK'];
        $retried = $this->service->retryDelivery($failed->getId(), $successClient);

        $this->assertTrue($retried->isSuccess());
        $this->assertSame(2, $retried->getAttemptNumber());
        $this->assertSame(200, $retried->getStatusCode());
        $this->assertNull($retried->getNextRetryAt());
    }

    public function testProcessPendingRetries(): void
    {
        $endpoint = $this->service->registerEndpoint(
            targetUrl: 'https://webhook.site/batch-retry',
            events: ['*']
        );

        $deliveries = $this->service->dispatch('domain.renewed', ['domain_id' => 12]);
        $delivery = $deliveries[0];

        // Initial failure
        $this->service->deliver($delivery, fn() => ['code' => 504, 'body' => 'Gateway Timeout']);

        // Check retries scanner with reference time set to +5 minutes in future
        $futureRef = date('c', time() + 300);
        $successClient = fn() => ['code' => 200, 'body' => 'OK'];

        $processedCount = $this->service->processPendingRetries($successClient, $futureRef);
        $this->assertSame(1, $processedCount);

        // Verify updated delivery in database
        $checked = $this->service->findDelivery($delivery->getId());
        $this->assertNotNull($checked);
        $this->assertTrue($checked->isSuccess());
        $this->assertSame(2, $checked->getAttemptNumber());
    }
}
