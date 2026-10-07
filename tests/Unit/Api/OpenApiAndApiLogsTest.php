<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Api;

use Coleza\Api\Docs\OpenApiGenerator;
use Coleza\Api\Logging\ApiRequestLogger;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class OpenApiAndApiLogsTest extends TestCase
{
    private Connection $connection;
    private ApiRequestLogger $logger;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->connection = new Connection($pdo, 'sqlite');
        $this->logger = new ApiRequestLogger($this->connection);
    }

    public function testOpenApiSchemaGeneration(): void
    {
        $generator = new OpenApiGenerator('Coleza Host REST API', '1.0.0', 'https://api.colezahost.com/api/v1');

        $generator->addEndpoint(
            path: '/invoices',
            method: 'GET',
            summary: 'List customer invoices',
            scopes: ['invoices.read']
        );

        $generator->addEndpoint(
            path: '/servers/{id}/reboot',
            method: 'POST',
            summary: 'Reboot VPS instance',
            scopes: ['servers.manage']
        );

        $schema = $generator->generate();

        $this->assertSame('3.1.0', $schema['openapi']);
        $this->assertSame('Coleza Host REST API', $schema['info']['title']);
        $this->assertArrayHasKey('/invoices', $schema['paths']);
        $this->assertArrayHasKey('get', $schema['paths']['/invoices']);
        $this->assertSame('List customer invoices', $schema['paths']['/invoices']['get']['summary']);
        $this->assertSame(['invoices.read'], $schema['paths']['/invoices']['get']['security'][0]['ApiKeyAuth']);

        // Check components security scheme
        $this->assertArrayHasKey('ApiKeyAuth', $schema['components']['securitySchemes']);
        $this->assertSame('X-API-KEY', $schema['components']['securitySchemes']['ApiKeyAuth']['name']);
    }

    public function testApiRequestLoggerWritesAndQueriesRecords(): void
    {
        $keyId = 7;
        $id1 = $this->logger->log(
            method: 'GET',
            path: '/api/v1/invoices',
            statusCode: 200,
            durationMs: 45,
            keyId: $keyId,
            ip: '192.168.1.50',
            requestId: 'req_abc123'
        );

        $id2 = $this->logger->log(
            method: 'POST',
            path: '/api/v1/servers/12/reboot',
            statusCode: 202,
            durationMs: 120,
            keyId: $keyId,
            ip: '192.168.1.50',
            requestId: 'req_def456'
        );

        $this->assertGreaterThan(0, $id1);
        $this->assertGreaterThan(0, $id2);

        $logs = $this->logger->getLogsForKey($keyId);
        $this->assertCount(2, $logs);
        $this->assertSame('POST', $logs[0]['method']);
        $this->assertSame('/api/v1/servers/12/reboot', $logs[0]['path']);
        $this->assertSame(202, (int) $logs[0]['status_code']);
        $this->assertSame(120, (int) $logs[0]['duration_ms']);
    }
}
