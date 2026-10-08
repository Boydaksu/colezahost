<?php

declare(strict_types=1);

namespace Tests\Unit\Providers\Cpanel;

use Coleza\Domain\Providers\Contracts\ProviderCapability;
use Coleza\Domain\Providers\Cpanel\CpanelApiClient;
use Coleza\Domain\Providers\Cpanel\CpanelConfiguration;
use Coleza\Domain\Providers\Cpanel\CpanelMemoryTransport;
use Coleza\Domain\Providers\Cpanel\CpanelPackageDto;
use Coleza\Domain\Providers\Cpanel\CpanelPackageService;
use Coleza\Domain\Providers\Cpanel\CpanelProvider;
use Coleza\Domain\Providers\Cpanel\CpanelServerHealthChecker;
use Coleza\Domain\Providers\DTO\ServerConnectionDto;
use Coleza\Domain\Providers\Exceptions\ProviderException;
use Coleza\Domain\Providers\Registry\ProviderRegistry;
use Coleza\Domain\Servers\Entities\Server;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CpanelCoreAndHealthTest extends TestCase
{
    private CpanelMemoryTransport $transport;
    private CpanelConfiguration $config;
    private CpanelApiClient $client;
    private CpanelPackageService $packageService;

    protected function setUp(): void
    {
        $this->transport = new CpanelMemoryTransport();
        $this->config = new CpanelConfiguration(
            hostname: 'srv1.examplehosting.com',
            username: 'root',
            apiToken: 'WHM_SECRET_TOKEN_ABC123',
            port: 2087,
            useSsl: true,
            timeoutSeconds: 15
        );
        $this->client = new CpanelApiClient($this->config, $this->transport);
        $this->packageService = new CpanelPackageService();
    }

    public function testConfigurationInitializationAndSecretMasking(): void
    {
        $this->assertSame('srv1.examplehosting.com', $this->config->getHostname());
        $this->assertSame('root', $this->config->getUsername());
        $this->assertSame('https://srv1.examplehosting.com:2087', $this->config->getBaseUrl());
        $this->assertSame('api_token', $this->config->getAuthType());

        $masked = $this->config->getMaskedSecret();
        $this->assertStringStartsWith('WH', $masked);
        $this->assertStringEndsWith('23', $masked);
        $this->assertStringContainsString('•', $masked);
        $this->assertStringNotContainsString('SECRET_TOKEN', $masked);

        $safeArray = $this->config->toSafeArray();
        $this->assertSame($masked, $safeArray['auth_secret']);
        $this->assertSame('https://srv1.examplehosting.com:2087', $safeArray['base_url']);

        // __debugInfo must mask secrets
        $debug = $this->config->__debugInfo();
        $this->assertSame($masked, $debug['auth_secret']);
    }

    public function testConfigurationValidationRejectsInvalidParameters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CpanelConfiguration(hostname: '', username: 'root', apiToken: 'token');
    }

    public function testConfigurationValidationRejectsMissingCredentials(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CpanelConfiguration(hostname: 'srv.com', username: 'root');
    }

    public function testConfigurationFromServerConnectionDto(): void
    {
        $conn = new ServerConnectionDto(
            serverId: 10,
            hostname: 'cpanel2.nodes.internal',
            ipAddress: '10.0.0.5',
            port: 2087,
            secure: true,
            authType: 'access_hash',
            authSecret: "HASH_LINE_1\nHASH_LINE_2",
            options: ['whm_username' => 'reseller_admin', 'timeout_seconds' => 20]
        );

        $config = CpanelConfiguration::fromServerConnection($conn);
        $this->assertSame('cpanel2.nodes.internal', $config->getHostname());
        $this->assertSame('reseller_admin', $config->getUsername());
        $this->assertSame('access_hash', $config->getAuthType());
        $this->assertSame("HASH_LINE_1\nHASH_LINE_2", $config->getAccessHash());
        $this->assertSame(20, $config->getTimeoutSeconds());
    }

    public function testApiClientAuthenticationHeaderGeneration(): void
    {
        // 1. API Token header
        $this->transport->stageResponse('version', 200, [
            'metadata' => ['result' => 1, 'reason' => 'OK', 'version' => 1],
            'data' => ['version' => '11.110.0.15'],
        ]);

        $this->client->testAuthentication();
        $lastReq = $this->transport->getLastRequest();
        $this->assertNotNull($lastReq);
        $this->assertSame('whm root:WHM_SECRET_TOKEN_ABC123', $lastReq['headers']['Authorization']);

        // 2. Access Hash header
        $hashConfig = new CpanelConfiguration(
            hostname: 'srv1.example.com',
            username: 'root',
            accessHash: "SECRET_HASH_HERE\n"
        );
        $hashClient = new CpanelApiClient($hashConfig, $this->transport);
        $hashClient->testAuthentication();
        $lastReqHash = $this->transport->getLastRequest();
        $this->assertSame('WHM root:SECRET_HASH_HERE', $lastReqHash['headers']['Authorization']);

        // 3. Basic password header
        $passConfig = new CpanelConfiguration(
            hostname: 'srv1.example.com',
            username: 'root',
            password: 'secret_root_password'
        );
        $passClient = new CpanelApiClient($passConfig, $this->transport);
        $passClient->testAuthentication();
        $lastReqPass = $this->transport->getLastRequest();
        $this->assertSame('Basic ' . base64_encode('root:secret_root_password'), $lastReqPass['headers']['Authorization']);
    }

    public function testApiClientTestAuthenticationSuccess(): void
    {
        $this->transport->stageResponse('version', 200, [
            'metadata' => ['result' => 1, 'reason' => 'OK', 'version' => 1],
            'data' => ['version' => '11.116.0.8'],
        ], latencyMs: 35);

        $result = $this->client->testAuthentication();
        $this->assertTrue($result['authenticated']);
        $this->assertSame('11.116.0.8', $result['version']);
        $this->assertSame(35, $result['latency_ms']);
    }

    public function testApiClientGetSystemLoad(): void
    {
        $this->transport->stageResponse('systemloadavg', 200, [
            'metadata' => ['result' => 1, 'reason' => 'OK', 'version' => 1],
            'data' => [
                'one' => '1.25',
                'five' => '0.95',
                'fifteen' => '0.80',
            ],
        ]);

        $load = $this->client->getSystemLoad();
        $this->assertSame(1.25, $load['1m']);
        $this->assertSame(0.95, $load['5m']);
        $this->assertSame(0.80, $load['15m']);
    }

    public function testApiClientHandlesAuthenticationFailure(): void
    {
        $this->transport->stageResponse('version', 401, 'Unauthorized access');

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('authentication rejected');

        try {
            $this->client->testAuthentication();
        } catch (ProviderException $e) {
            $this->assertSame('AUTHENTICATION_FAILED', $e->getErrorCode());
            $this->assertSame('authentication', $e->getContext()['category']);
            throw $e;
        }
    }

    public function testApiClientHandlesWhmApiErrorResponse(): void
    {
        $this->transport->stageResponse('listpkgs', 200, [
            'metadata' => [
                'result' => 0,
                'reason' => 'Permission denied: reseller cannot view system packages',
                'version' => 1,
            ],
        ]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Permission denied');

        try {
            $this->client->listPackagesRaw();
        } catch (ProviderException $e) {
            $this->assertSame('WHM_API_ERROR', $e->getErrorCode());
            $this->assertArrayHasKey('admin_advice', $e->getContext());
            throw $e;
        }
    }

    public function testPackageDtoParsingFromWhmArray(): void
    {
        $whmPkg = [
            'name' => 'starter_plan',
            'QUOTA' => '5120',
            'BWLIMIT' => '51200',
            'MAXFTP' => '5',
            'MAXSQL' => '10',
            'MAXPOP' => '20',
            'MAXSUB' => '3',
            'MAXPARK' => '1',
            'MAXADDON' => '0',
            'HASCGI' => 'y',
            'HASHELL' => 'n',
            'IP' => 'n',
            'CPMOD' => 'jupiter',
            'FEATURELIST' => 'standard',
        ];

        $dto = CpanelPackageDto::fromWhmArray($whmPkg);

        $this->assertSame('starter_plan', $dto->getName());
        $this->assertSame(5120, $dto->getQuotaMb());
        $this->assertFalse($dto->isUnlimitedQuota());
        $this->assertSame(51200, $dto->getBandwidthMb());
        $this->assertFalse($dto->isUnlimitedBandwidth());
        $this->assertSame(5, $dto->getMaxFtp());
        $this->assertSame(10, $dto->getMaxSql());
        $this->assertTrue($dto->hasCgiAccess());
        $this->assertFalse($dto->hasShellAccess());
        $this->assertFalse($dto->hasDedicatedIp());
        $this->assertSame('jupiter', $dto->getCpanelTheme());

        $array = $dto->toArray();
        $this->assertSame('starter_plan', $array['name']);
        $this->assertSame(5120, $array['quota_mb']);
    }

    public function testPackageDtoUnlimitedHandling(): void
    {
        $whmPkg = [
            'name' => 'unlimited_biz',
            'QUOTA' => 'unlimited',
            'BWLIMIT' => 'unlimited',
            'IP' => 'y',
        ];

        $dto = CpanelPackageDto::fromWhmArray($whmPkg);

        $this->assertSame('unlimited_biz', $dto->getName());
        $this->assertSame(0, $dto->getQuotaMb());
        $this->assertTrue($dto->isUnlimitedQuota());
        $this->assertSame(0, $dto->getBandwidthMb());
        $this->assertTrue($dto->isUnlimitedBandwidth());
        $this->assertTrue($dto->hasDedicatedIp());
    }

    public function testPackageServiceListAndResolution(): void
    {
        $this->transport->stageResponse('listpkgs', 200, [
            'metadata' => ['result' => 1, 'reason' => 'OK', 'version' => 1],
            'data' => [
                'pkg' => [
                    [
                        'name' => 'starter',
                        'QUOTA' => '2048',
                        'BWLIMIT' => '20480',
                        'IP' => 'n',
                    ],
                    [
                        'name' => 'reseller_premium',
                        'QUOTA' => '10240',
                        'BWLIMIT' => '102400',
                        'IP' => 'y',
                    ],
                ],
            ],
        ]);

        $packages = $this->packageService->listPackages($this->client);
        $this->assertCount(2, $packages);
        $this->assertArrayHasKey('starter', $packages);
        $this->assertArrayHasKey('reseller_premium', $packages);

        // Exact match
        $exact = $this->packageService->resolvePackage($this->client, 'starter');
        $this->assertSame('starter', $exact->getName());

        // Case-insensitive match
        $caseInsensitive = $this->packageService->resolvePackage($this->client, 'STARTER');
        $this->assertSame('starter', $caseInsensitive->getName());

        // Reseller prefix match ("premium" matches "reseller_premium")
        $resellerMatch = $this->packageService->resolvePackage($this->client, 'premium');
        $this->assertSame('reseller_premium', $resellerMatch->getName());
    }

    public function testPackageServiceResolutionNotFoundThrowsException(): void
    {
        $this->transport->stageResponse('listpkgs', 200, [
            'metadata' => ['result' => 1, 'reason' => 'OK', 'version' => 1],
            'data' => [
                'pkg' => [
                    ['name' => 'starter', 'QUOTA' => '1024'],
                ],
            ],
        ]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage("cPanel/WHM package 'nonexistent' was not found");

        $this->packageService->resolvePackage($this->client, 'nonexistent');
    }

    public function testPackageServiceCompatibilityValidation(): void
    {
        $package = CpanelPackageDto::fromWhmArray([
            'name' => 'silver_plan',
            'QUOTA' => '5000',
            'BWLIMIT' => '50000',
            'IP' => 'n',
        ]);

        // Compatible limits
        $result = $this->packageService->validatePackageCompatibility($package, [
            'disk_limit_mb' => 4000,
            'bandwidth_limit_mb' => 40000,
            'dedicated_ip' => false,
        ]);
        $this->assertTrue($result['compatible']);
        $this->assertEmpty($result['violations']);

        // Incompatible limits (exceeds disk, requires dedicated IP)
        $incompat = $this->packageService->validatePackageCompatibility($package, [
            'disk_limit_mb' => 10000,
            'dedicated_ip' => true,
        ]);
        $this->assertFalse($incompat['compatible']);
        $this->assertCount(2, $incompat['violations']);
        $this->assertStringContainsString('quota (5000 MB) is less than required quota (10000 MB)', $incompat['violations'][0]);
        $this->assertStringContainsString('does not provide dedicated IP', $incompat['violations'][1]);
    }

    public function testServerHealthCheckerHealthy(): void
    {
        $this->transport->stageResponse('version', 200, [
            'metadata' => ['result' => 1, 'reason' => 'OK'],
            'data' => ['version' => '11.112.0.10'],
        ], latencyMs: 25);

        $this->transport->stageResponse('systemloadavg', 200, [
            'metadata' => ['result' => 1, 'reason' => 'OK'],
            'data' => ['one' => '2.40', 'five' => '1.80', 'fifteen' => '1.20'],
        ]);

        $healthChecker = new CpanelServerHealthChecker($this->transport);
        $server = new Server(
            id: 1,
            name: 'Node 1',
            hostname: 'node1.hosting.com',
            ipAddress: '192.168.1.100',
            providerSlug: 'cpanel',
            authType: 'api_token',
            authSecret: 'VALID_SECRET_TOKEN_XYZ'
        );

        $result = $healthChecker->checkHealth($server);
        $this->assertTrue($result->isHealthy());
        $this->assertSame(25, $result->getResponseTimeMs());
        $this->assertStringContainsString('operational (v11.112.0.10, load: 2.40)', $result->getMessage());
        $this->assertSame(2.40, $result->getDetails()['load_1m']);
    }

    public function testServerHealthCheckerUnhealthyOnCriticalLoad(): void
    {
        $this->transport->stageResponse('version', 200, [
            'metadata' => ['result' => 1, 'reason' => 'OK'],
            'data' => ['version' => '11.112.0.10'],
        ]);

        $this->transport->stageResponse('systemloadavg', 200, [
            'metadata' => ['result' => 1, 'reason' => 'OK'],
            'data' => ['one' => '32.50', 'five' => '28.00', 'fifteen' => '22.00'],
        ]);

        $healthChecker = new CpanelServerHealthChecker($this->transport, maxLoadThreshold: 25.0);
        $server = new Server(
            id: 1,
            name: 'Overloaded Node',
            hostname: 'overloaded.hosting.com',
            ipAddress: '192.168.1.101',
            providerSlug: 'cpanel',
            authType: 'api_token',
            authSecret: 'SECRET_TOKEN'
        );

        $result = $healthChecker->checkHealth($server);
        $this->assertFalse($result->isHealthy());
        $this->assertStringContainsString('critical load average (32.50 > 25.00 threshold)', $result->getMessage());
    }

    public function testServerHealthCheckerUnhealthyOnUnreachableServer(): void
    {
        $this->transport->stageResponse('version', 500, 'Server crashed');

        $healthChecker = new CpanelServerHealthChecker($this->transport);
        $server = new Server(
            id: 2,
            name: 'Failing Node',
            hostname: 'down.hosting.com',
            ipAddress: '192.168.1.102',
            providerSlug: 'cpanel',
            authType: 'api_token',
            authSecret: 'SECRET_TOKEN'
        );

        $result = $healthChecker->checkHealth($server);
        $this->assertFalse($result->isHealthy());
        $this->assertStringContainsString('health check failed', $result->getMessage());
    }

    public function testCpanelProviderCapabilitiesAndRegistry(): void
    {
        $provider = new CpanelProvider($this->transport);

        $this->assertSame('cpanel', $provider->getSlug());
        $this->assertSame('cPanel & WHM', $provider->getName());
        $this->assertSame('1.0.0', $provider->getVersion());

        // Verify supported capabilities
        $this->assertTrue($provider->supports(ProviderCapability::CREATE_ACCOUNT));
        $this->assertTrue($provider->supports(ProviderCapability::SUSPEND_ACCOUNT));
        $this->assertTrue($provider->supports(ProviderCapability::UNSUSPEND_ACCOUNT));
        $this->assertTrue($provider->supports(ProviderCapability::TERMINATE_ACCOUNT));
        $this->assertTrue($provider->supports(ProviderCapability::CHANGE_PACKAGE));
        $this->assertTrue($provider->supports(ProviderCapability::CHANGE_PASSWORD));
        $this->assertTrue($provider->supports(ProviderCapability::USAGE_METRICS));
        $this->assertTrue($provider->supports(ProviderCapability::SINGLE_SIGN_ON));
        $this->assertTrue($provider->supports(ProviderCapability::CUSTOM_ACTION));

        // Registry integration
        $registry = new ProviderRegistry([$provider]);
        $this->assertTrue($registry->has('cpanel'));
        $this->assertSame($provider, $registry->get('cpanel'));

        $capableProviders = $registry->getByCapability(ProviderCapability::CREATE_ACCOUNT);
        $this->assertArrayHasKey('cpanel', $capableProviders);
    }
}
