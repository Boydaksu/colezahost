<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Api;

use Coleza\Api\Auth\ApiKeyService;
use Coleza\Api\RateLimit\RateLimiter;
use Coleza\Api\Response\ApiResponse;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class ApiFoundationTest extends TestCase
{
    private Connection $connection;
    private ApiKeyService $apiKeyService;
    private RateLimiter $rateLimiter;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->connection = new Connection($pdo, 'sqlite');
        $this->apiKeyService = new ApiKeyService($this->connection);
        $this->rateLimiter = new RateLimiter($this->connection);
    }

    public function testApiKeyCreationAndAuthentication(): void
    {
        $created = $this->apiKeyService->createKey(
            userId: 5,
            name: 'Production Billing Automation',
            scopes: ['invoices.read', 'invoices.write']
        );

        $this->assertStringStartsWith('col_', $created['key_prefix']);
        $this->assertNotEmpty($created['plain_key']);

        // Authenticate with valid scope
        $authData = $this->apiKeyService->authenticate($created['plain_key'], 'invoices.read');
        $this->assertSame(5, $authData['user_id']);
        $this->assertSame('Production Billing Automation', $authData['name']);

        // Authenticate with missing scope throws ValidationException
        $this->expectException(ValidationException::class);
        $this->apiKeyService->authenticate($created['plain_key'], 'servers.reboot');
    }

    public function testExpiredApiKeyFailsAuthentication(): void
    {
        $expiredKey = $this->apiKeyService->createKey(
            userId: 1,
            name: 'Old Temporary Key',
            scopes: ['*'],
            expiresAt: time() - 3600
        );

        $this->expectException(ValidationException::class);
        $this->apiKeyService->authenticate($expiredKey['plain_key']);
    }

    public function testRateLimiterLimitsRequests(): void
    {
        $key = 'ip:192.168.1.100';

        // 3 requests within limit
        $r1 = $this->rateLimiter->hit($key, maxRequests: 3, windowSeconds: 60);
        $this->assertSame(2, $r1['remaining']);

        $r2 = $this->rateLimiter->hit($key, maxRequests: 3, windowSeconds: 60);
        $this->assertSame(1, $r2['remaining']);

        $r3 = $this->rateLimiter->hit($key, maxRequests: 3, windowSeconds: 60);
        $this->assertSame(0, $r3['remaining']);

        // 4th request exceeds limit -> exception
        $this->expectException(ValidationException::class);
        $this->rateLimiter->hit($key, maxRequests: 3, windowSeconds: 60);
    }

    public function testApiResponseFormatters(): void
    {
        // Success
        $success = ApiResponse::success(['id' => 123], ['cached' => true]);
        $this->assertSame('success', $success['status']);
        $this->assertSame(200, $success['code']);
        $this->assertSame(123, $success['data']['id']);
        $this->assertTrue($success['meta']['cached']);

        // Pagination
        $paginated = ApiResponse::paginate([['item' => 'a'], ['item' => 'b']], total: 50, page: 2, perPage: 10);
        $this->assertSame(2, $paginated['pagination']['current_page']);
        $this->assertSame(5, $paginated['pagination']['total_pages']);
        $this->assertTrue($paginated['pagination']['has_next']);
        $this->assertTrue($paginated['pagination']['has_prev']);

        // Error
        $error = ApiResponse::error('Not found', 'RESOURCE_NOT_FOUND', 404, ['id' => ['Entity not found']]);
        $this->assertSame('error', $error['status']);
        $this->assertSame(404, $error['code']);
        $this->assertSame('RESOURCE_NOT_FOUND', $error['error_code']);
        $this->assertSame('Not found', $error['message']);
        $this->assertArrayHasKey('id', $error['errors']);
    }
}
