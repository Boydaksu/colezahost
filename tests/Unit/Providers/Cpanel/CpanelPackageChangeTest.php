<?php

declare(strict_types=1);

namespace Tests\Unit\Providers\Cpanel;

use Coleza\Domain\Providers\Contracts\ProviderCapability;
use Coleza\Domain\Providers\Cpanel\CpanelApiClient;
use Coleza\Domain\Providers\Cpanel\CpanelConfiguration;
use Coleza\Domain\Providers\Cpanel\CpanelMemoryTransport;
use Coleza\Domain\Providers\Cpanel\CpanelProvider;
use Coleza\Domain\Providers\DTO\ServerConnectionDto;
use Coleza\Domain\Providers\Operations\ChangePackageOperation;
use PHPUnit\Framework\TestCase;

final class CpanelPackageChangeTest extends TestCase
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
                    ],
                    [
                        'name' => 'business_plus',
                        'QUOTA' => '10240',
                        'BWLIMIT' => '102400',
                    ],
                    [
                        'name' => 'enterprise',
                        'QUOTA' => '51200',
                        'BWLIMIT' => '512000',
                    ],
                ],
            ],
        ]);
    }

    public function testChangePackageSuccess(): void
    {
        $this->transport->stageResponse('accountsummary', 200, [
            'metadata' => ['result' => 1, 'reason' => 'OK'],
            'data' => [
                'acct' => [
                    [
                        'user' => 'colezauser',
                        'plan' => 'starter',
                    ],
                ],
            ],
        ]);

        $this->transport->stageResponse('changepackage', 200, [
            'metadata' => [
                'result' => 1,
                'reason' => 'Package changed to business_plus successfully.',
                'version' => 1,
            ],
            'data' => [
                'user' => 'colezauser',
                'pkg' => 'business_plus',
            ],
        ]);

        $op = new ChangePackageOperation(
            serviceId: 101,
            username: 'colezauser',
            newPackageIdentifier: 'business_plus',
            server: $this->serverConn
        );

        $result = $this->provider->changePackage($op);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(ProviderCapability::CHANGE_PACKAGE, $result->getOperationType());
        $this->assertSame('colezauser', $result->getExternalIdentifier());
        $this->assertFalse($result->getData()['idempotent']);
        $this->assertSame('business_plus', $result->getData()['package']);
        $this->assertSame('starter', $result->getData()['previous_package']);

        $history = $this->transport->getRecordedRequests();
        $endpoints = array_map(fn($r) => $r['url'], $history);
        $this->assertTrue(array_filter($endpoints, fn($u) => str_contains($u, 'changepackage')) !== []);
    }

    public function testChangePackageIdempotentWhenAlreadyOnPackageAndNoQuotas(): void
    {
        $this->transport->stageResponse('accountsummary', 200, [
            'metadata' => ['result' => 1, 'reason' => 'OK'],
            'data' => [
                'acct' => [
                    [
                        'user' => 'colezauser',
                        'plan' => 'starter',
                    ],
                ],
            ],
        ]);

        $op = new ChangePackageOperation(
            serviceId: 101,
            username: 'colezauser',
            newPackageIdentifier: 'starter',
            server: $this->serverConn
        );

        $result = $this->provider->changePackage($op);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->getData()['idempotent']);
        $this->assertSame('starter', $result->getData()['package']);

        // WHM changepackage should NOT have been invoked because summary showed already matching
        $history = $this->transport->getRecordedRequests();
        $endpoints = array_map(fn($r) => $r['url'], $history);
        $this->assertEmpty(array_filter($endpoints, fn($u) => str_contains($u, 'changepackage')));
    }

    public function testChangePackageIdempotentFromWhmMessage(): void
    {
        $this->transport->stageResponse('accountsummary', 200, [
            'metadata' => ['result' => 1, 'reason' => 'OK'],
            'data' => [
                'acct' => [
                    [
                        'user' => 'colezauser',
                        'plan' => 'old_plan',
                    ],
                ],
            ],
        ]);

        $this->transport->stageResponse('changepackage', 200, [
            'metadata' => [
                'result' => 0,
                'reason' => 'Account is already on package starter.',
                'version' => 1,
            ],
        ]);

        $op = new ChangePackageOperation(
            serviceId: 101,
            username: 'colezauser',
            newPackageIdentifier: 'starter',
            server: $this->serverConn
        );

        $result = $this->provider->changePackage($op);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->getData()['idempotent']);
        $this->assertSame('starter', $result->getData()['package']);
    }

    public function testChangePackageWithCustomQuotas(): void
    {
        $this->transport->stageResponse('accountsummary', 200, [
            'metadata' => ['result' => 1, 'reason' => 'OK'],
            'data' => [
                'acct' => [
                    [
                        'user' => 'colezauser',
                        'plan' => 'starter',
                    ],
                ],
            ],
        ]);

        $this->transport->stageResponse('changepackage', 200, [
            'metadata' => ['result' => 1, 'reason' => 'Package changed'],
        ]);

        $this->transport->stageResponse('editquota', 200, [
            'metadata' => ['result' => 1, 'reason' => 'Disk quota updated to 25600'],
        ]);

        $this->transport->stageResponse('limitbw', 200, [
            'metadata' => ['result' => 1, 'reason' => 'Bandwidth limit updated to 256000'],
        ]);

        $op = new ChangePackageOperation(
            serviceId: 101,
            username: 'colezauser',
            newPackageIdentifier: 'business_plus',
            resourceQuotas: [
                'disk_limit_mb' => 25600,
                'bandwidth_limit_mb' => 256000,
            ],
            server: $this->serverConn
        );

        $result = $this->provider->changePackage($op);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(25600, $result->getData()['quotas_applied']['disk_limit_mb']);
        $this->assertSame(256000, $result->getData()['quotas_applied']['bandwidth_limit_mb']);

        $history = $this->transport->getRecordedRequests();
        $quotaCalls = array_filter($history, fn($r) => str_contains($r['url'], 'editquota'));
        $bwCalls = array_filter($history, fn($r) => str_contains($r['url'], 'limitbw'));

        $this->assertCount(1, $quotaCalls);
        $this->assertCount(1, $bwCalls);

        $quotaCall = array_values($quotaCalls)[0];
        $this->assertSame(25600, $quotaCall['body_params']['quota']);
        $this->assertSame('colezauser', $quotaCall['body_params']['user']);

        $bwCall = array_values($bwCalls)[0];
        $this->assertSame(256000, $bwCall['body_params']['bwlimit']);
        $this->assertSame('colezauser', $bwCall['body_params']['user']);
    }

    public function testChangePackageFailsWhenServerMissing(): void
    {
        $op = new ChangePackageOperation(
            serviceId: 101,
            username: 'colezauser',
            newPackageIdentifier: 'business_plus',
            server: null
        );

        $result = $this->provider->changePackage($op);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('MISSING_SERVER_CONNECTION', $result->getErrorCode());
    }

    public function testChangePackageFailsWhenPackageNotFound(): void
    {
        $this->transport->stageResponse('accountsummary', 200, [
            'metadata' => ['result' => 1, 'reason' => 'OK'],
            'data' => [
                'acct' => [['user' => 'colezauser', 'plan' => 'starter']],
            ],
        ]);

        $op = new ChangePackageOperation(
            serviceId: 101,
            username: 'colezauser',
            newPackageIdentifier: 'non_existent_plan',
            server: $this->serverConn
        );

        $result = $this->provider->changePackage($op);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('PROVIDER_EXCEPTION', $result->getErrorCode());
    }

    public function testChangePackageFailsWhenWhmFails(): void
    {
        $this->transport->stageResponse('accountsummary', 200, [
            'metadata' => ['result' => 1, 'reason' => 'OK'],
            'data' => [
                'acct' => [['user' => 'colezauser', 'plan' => 'starter']],
            ],
        ]);

        $this->transport->stageResponse('changepackage', 200, [
            'metadata' => [
                'result' => 0,
                'reason' => 'User colezauser does not exist or has corrupt files.',
            ],
        ]);

        $op = new ChangePackageOperation(
            serviceId: 101,
            username: 'colezauser',
            newPackageIdentifier: 'business_plus',
            server: $this->serverConn
        );

        $result = $this->provider->changePackage($op);

        $this->assertFalse($result->isSuccess());
        $this->assertNotEmpty($result->getErrorCode());
        $this->assertArrayHasKey('admin_advice', $result->getData());
    }

    public function testApiClientDirectPackageAndQuotaMethods(): void
    {
        $config = CpanelConfiguration::fromServerConnection($this->serverConn);
        $client = new CpanelApiClient($config, $this->transport);

        $this->transport->stageResponse('changepackage', 200, [
            'metadata' => ['result' => 1, 'reason' => 'Changed package'],
        ]);
        $this->transport->stageResponse('editquota', 200, [
            'metadata' => ['result' => 1, 'reason' => 'Quota changed'],
        ]);
        $this->transport->stageResponse('limitbw', 200, [
            'metadata' => ['result' => 1, 'reason' => 'Bandwidth changed'],
        ]);

        $resPkg = $client->changePackage('testuser', 'gold_plan');
        $this->assertSame(1, $resPkg['metadata']['result']);

        $resQuota = $client->editQuota('testuser', 5000);
        $this->assertSame(1, $resQuota['metadata']['result']);

        $resBw = $client->limitBandwidth('testuser', 50000);
        $this->assertSame(1, $resBw['metadata']['result']);
    }
}
