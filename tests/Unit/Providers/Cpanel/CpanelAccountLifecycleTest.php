<?php

declare(strict_types=1);

namespace Tests\Unit\Providers\Cpanel;

use Coleza\Domain\Providers\Contracts\ProviderCapability;
use Coleza\Domain\Providers\Cpanel\CpanelApiClient;
use Coleza\Domain\Providers\Cpanel\CpanelConfiguration;
use Coleza\Domain\Providers\Cpanel\CpanelMemoryTransport;
use Coleza\Domain\Providers\Cpanel\CpanelPackageService;
use Coleza\Domain\Providers\Cpanel\CpanelProvider;
use Coleza\Domain\Providers\DTO\ServerConnectionDto;
use Coleza\Domain\Providers\Operations\CreateAccountOperation;
use Coleza\Domain\Providers\Operations\SuspendAccountOperation;
use Coleza\Domain\Providers\Operations\TerminateAccountOperation;
use Coleza\Domain\Providers\Operations\UnsuspendAccountOperation;
use PHPUnit\Framework\TestCase;

final class CpanelAccountLifecycleTest extends TestCase
{
    private CpanelMemoryTransport $transport;
    private CpanelProvider $provider;
    private ServerConnectionDto $serverConn;

    protected function setUp(): void
    {
        $this->transport = new CpanelMemoryTransport();
        $this->provider = new CpanelProvider($this->transport);

        $this->serverConn = new ServerConnectionDto(
            serverId: 1,
            hostname: 'whm.node1.colezahost.com',
            ipAddress: '192.168.1.50',
            port: 2087,
            secure: true,
            authType: 'api_token',
            authSecret: 'VALID_WHM_TOKEN_12345',
            options: ['whm_username' => 'root']
        );

        // Stage default listpkgs so packageService can resolve packages
        $this->transport->stageResponse('listpkgs', 200, [
            'metadata' => ['result' => 1, 'reason' => 'OK'],
            'data' => [
                'pkg' => [
                    [
                        'name' => 'starter',
                        'QUOTA' => '2048',
                        'BWLIMIT' => '20480',
                        'IP' => 'n',
                    ],
                    [
                        'name' => 'business_plus',
                        'QUOTA' => '10240',
                        'BWLIMIT' => '102400',
                        'IP' => 'y',
                    ],
                ],
            ],
        ]);
    }

    public function testCreateAccountSuccess(): void
    {
        $this->transport->stageResponse('createacct', 200, [
            'metadata' => [
                'result' => 1,
                'reason' => 'Account Creation Ok',
                'version' => 1,
            ],
            'data' => [
                'ip' => '192.168.1.55',
                'nameserver' => 'ns1.colezahost.com',
                'nameserver2' => 'ns2.colezahost.com',
                'package' => 'starter',
            ],
        ]);

        $op = new CreateAccountOperation(
            serviceId: 101,
            serviceNumber: 'SRV-20261008-000001',
            username: 'alicehost',
            password: 'secret_strong_pass!1',
            domain: 'alice-site.org',
            packageIdentifier: 'starter',
            resourceQuotas: ['disk_limit_mb' => 2048],
            server: $this->serverConn,
            metadata: ['contact_email' => 'alice@example.com']
        );

        $result = $this->provider->createAccount($op);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(ProviderCapability::CREATE_ACCOUNT, $result->getOperationType());
        $this->assertSame('alicehost', $result->getExternalIdentifier());
        $this->assertSame('192.168.1.55', $result->getData()['ip']);
        $this->assertSame('starter', $result->getData()['package']);

        // Check request recorded by transport
        $lastReq = $this->transport->getLastRequest();
        $this->assertNotNull($lastReq);
        $this->assertSame('POST', $lastReq['method']);
        $this->assertStringContainsString('createacct', $lastReq['url']);
        $this->assertSame('alicehost', $lastReq['body_params']['username']);
        $this->assertSame('alice-site.org', $lastReq['body_params']['domain']);
        $this->assertSame('starter', $lastReq['body_params']['plan']);
        $this->assertSame('alice@example.com', $lastReq['body_params']['contactemail']);
    }

    public function testCreateAccountMissingServerConnection(): void
    {
        $op = new CreateAccountOperation(
            serviceId: 102,
            serviceNumber: 'SRV-20261008-000002',
            username: 'orphanuser',
            password: 'password123',
            domain: 'orphan.com',
            packageIdentifier: 'starter',
            server: null
        );

        $result = $this->provider->createAccount($op);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('MISSING_SERVER_CONNECTION', $result->getErrorCode());
        $this->assertStringContainsString('No server connection information provided', $result->getMessage());
    }

    public function testCreateAccountFailureWithWhmError(): void
    {
        $this->transport->stageResponse('createacct', 200, [
            'metadata' => [
                'result' => 0,
                'reason' => 'A DNS entry for the domain “alreadytaken.com” already exists.',
                'version' => 1,
            ],
        ]);

        $op = new CreateAccountOperation(
            serviceId: 103,
            serviceNumber: 'SRV-20261008-000003',
            username: 'duplicateuser',
            password: 'password123',
            domain: 'alreadytaken.com',
            packageIdentifier: 'starter',
            server: $this->serverConn
        );

        $result = $this->provider->createAccount($op);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('conflict', $result->getData()['category']);
        $this->assertStringContainsString('already exists', $result->getData()['raw_reason']);
    }

    public function testCreateAccountWithCustomQuotasAndDedicatedIp(): void
    {
        $this->transport->stageResponse('createacct', 200, [
            'metadata' => ['result' => 1, 'reason' => 'Account Creation Ok'],
            'data' => ['ip' => '192.168.1.99'],
        ]);

        $op = new CreateAccountOperation(
            serviceId: 104,
            serviceNumber: 'SRV-20261008-000004',
            username: 'vipclient',
            password: 'password123',
            domain: 'vipplatform.io',
            packageIdentifier: 'business_plus',
            resourceQuotas: [
                'disk_limit_mb' => 15000,
                'bandwidth_limit_mb' => 150000,
            ],
            server: $this->serverConn,
            metadata: ['dedicated_ip' => true]
        );

        $result = $this->provider->createAccount($op);

        $this->assertTrue($result->isSuccess());

        $lastReq = $this->transport->getLastRequest();
        $this->assertNotNull($lastReq);
        $this->assertSame(15000, $lastReq['body_params']['quota']);
        $this->assertSame(150000, $lastReq['body_params']['bwlimit']);
        $this->assertSame('y', $lastReq['body_params']['ip']);
    }

    public function testSuspendAccountSuccess(): void
    {
        $this->transport->stageResponse('suspendacct', 200, [
            'metadata' => [
                'result' => 1,
                'reason' => 'User bob123 has been suspended',
            ],
        ]);

        $op = new SuspendAccountOperation(
            serviceId: 201,
            username: 'bob123',
            reason: 'Invoice #1042 overdue by 14 days',
            server: $this->serverConn
        );

        $result = $this->provider->suspendAccount($op);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('bob123', $result->getExternalIdentifier());
        $this->assertFalse($result->getData()['idempotent']);

        $lastReq = $this->transport->getLastRequest();
        $this->assertNotNull($lastReq);
        $this->assertStringContainsString('suspendacct', $lastReq['url']);
        $this->assertSame('bob123', $lastReq['body_params']['user']);
        $this->assertSame('Invoice #1042 overdue by 14 days', $lastReq['body_params']['reason']);
    }

    public function testSuspendAccountIdempotency(): void
    {
        $this->transport->stageResponse('suspendacct', 200, [
            'metadata' => [
                'result' => 0,
                'reason' => 'bob123 is already suspended',
            ],
        ]);

        $op = new SuspendAccountOperation(
            serviceId: 201,
            username: 'bob123',
            reason: 'Second suspension attempt',
            server: $this->serverConn
        );

        $result = $this->provider->suspendAccount($op);

        // Idempotent: already suspended must report SUCCESS
        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->getData()['idempotent']);
    }

    public function testUnsuspendAccountSuccess(): void
    {
        $this->transport->stageResponse('unsuspendacct', 200, [
            'metadata' => [
                'result' => 1,
                'reason' => 'User charlie has been unsuspended',
            ],
        ]);

        $op = new UnsuspendAccountOperation(
            serviceId: 301,
            username: 'charlie',
            server: $this->serverConn
        );

        $result = $this->provider->unsuspendAccount($op);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('charlie', $result->getExternalIdentifier());
        $this->assertFalse($result->getData()['idempotent']);

        $lastReq = $this->transport->getLastRequest();
        $this->assertNotNull($lastReq);
        $this->assertStringContainsString('unsuspendacct', $lastReq['url']);
        $this->assertSame('charlie', $lastReq['body_params']['user']);
    }

    public function testUnsuspendAccountIdempotency(): void
    {
        $this->transport->stageResponse('unsuspendacct', 200, [
            'metadata' => [
                'result' => 0,
                'reason' => 'Account charlie is not suspended',
            ],
        ]);

        $op = new UnsuspendAccountOperation(
            serviceId: 301,
            username: 'charlie',
            server: $this->serverConn
        );

        $result = $this->provider->unsuspendAccount($op);

        // Idempotent: not suspended must report SUCCESS
        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->getData()['idempotent']);
    }

    public function testTerminateAccountSuccess(): void
    {
        $this->transport->stageResponse('removeacct', 200, [
            'metadata' => [
                'result' => 1,
                'reason' => 'Account Removal Complete',
            ],
        ]);

        $op = new TerminateAccountOperation(
            serviceId: 401,
            username: 'oldclient',
            keepBackup: false,
            server: $this->serverConn
        );

        $result = $this->provider->terminateAccount($op);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('oldclient', $result->getExternalIdentifier());
        $this->assertFalse($result->getData()['idempotent']);

        $lastReq = $this->transport->getLastRequest();
        $this->assertNotNull($lastReq);
        $this->assertStringContainsString('removeacct', $lastReq['url']);
        $this->assertSame('oldclient', $lastReq['body_params']['user']);
        $this->assertSame(0, $lastReq['body_params']['keepdns']);
    }

    public function testTerminateAccountIdempotency(): void
    {
        $this->transport->stageResponse('removeacct', 200, [
            'metadata' => [
                'result' => 0,
                'reason' => 'User oldclient does not exist on this server',
            ],
        ]);

        $op = new TerminateAccountOperation(
            serviceId: 401,
            username: 'oldclient',
            server: $this->serverConn
        );

        $result = $this->provider->terminateAccount($op);

        // Idempotent: already removed must report SUCCESS
        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->getData()['idempotent']);
    }

    public function testApiClientAccountSummarySuccessAndMissing(): void
    {
        $config = CpanelConfiguration::fromServerConnection($this->serverConn);
        $client = new CpanelApiClient($config, $this->transport);

        // 1. Account exists
        $this->transport->stageResponse('accountsummary', 200, [
            'metadata' => ['result' => 1, 'reason' => 'OK'],
            'data' => [
                'acct' => [
                    [
                        'user' => 'activeuser',
                        'domain' => 'active.com',
                        'plan' => 'starter',
                        'suspended' => 0,
                    ],
                ],
            ],
        ]);

        $summary = $client->getAccountSummary('activeuser');
        $this->assertNotNull($summary);
        $this->assertSame('activeuser', $summary['user']);
        $this->assertSame('active.com', $summary['domain']);

        // 2. Account does not exist
        $this->transport->stageResponse('accountsummary', 200, [
            'metadata' => ['result' => 0, 'reason' => 'Account does not exist'],
            'data' => [],
        ]);

        $missing = $client->getAccountSummary('ghostuser');
        $this->assertNull($missing);
    }
}
