<?php

declare(strict_types=1);

namespace Tests\Unit\Servers;

use Coleza\Domain\Servers\Entities\Location;
use Coleza\Domain\Servers\Entities\Server;
use Coleza\Domain\Servers\Entities\ServerCapacity;
use Coleza\Domain\Servers\Entities\ServerPool;
use Coleza\Domain\Servers\Services\ServerService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class ServerAndCapacityTest extends TestCase
{
    private Connection $db;
    private ServerService $serverService;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->serverService = new ServerService($this->db);
        $this->serverService->ensureTables();
    }

    public function testLocationCreationAndListing(): void
    {
        $loc1 = $this->serverService->createLocation([
            'name' => 'Frankfurt Equinix',
            'slug' => 'fra-eq01',
            'country_code' => 'de',
            'city' => 'Frankfurt',
            'datacenter' => 'Equinix FR2',
        ]);

        $this->assertSame('Frankfurt Equinix', $loc1->getName());
        $this->assertSame('fra-eq01', $loc1->getSlug());
        $this->assertSame('DE', $loc1->getCountryCode());
        $this->assertSame('Frankfurt', $loc1->getCity());
        $this->assertSame('Equinix FR2', $loc1->getDatacenter());
        $this->assertTrue($loc1->isActive());

        // Duplicate slug throws ValidationException
        $this->expectException(ValidationException::class);
        $this->serverService->createLocation([
            'name' => 'Frankfurt Duplicate',
            'slug' => 'fra-eq01',
            'country_code' => 'de',
            'city' => 'Frankfurt',
        ]);
    }

    public function testServerPoolCreationAndStrategies(): void
    {
        $loc = $this->serverService->createLocation([
            'name' => 'Amsterdam DataHub',
            'slug' => 'ams-01',
            'country_code' => 'nl',
            'city' => 'Amsterdam',
        ]);

        $pool = $this->serverService->createPool([
            'name' => 'cPanel High RAM Pool',
            'slug' => 'cpanel-nl-ram',
            'provider_slug' => 'cpanel',
            'location_id' => $loc->getId(),
            'strategy' => ServerPool::STRATEGY_LEAST_LOADED,
            'metadata' => ['env' => 'production'],
        ]);

        $this->assertSame('cPanel High RAM Pool', $pool->getName());
        $this->assertSame('cpanel', $pool->getProviderSlug());
        $this->assertSame(ServerPool::STRATEGY_LEAST_LOADED, $pool->getStrategy());
        $this->assertSame($loc->getId(), $pool->getLocationId());
        $this->assertTrue($pool->isActive());

        // Update pool strategy
        $updated = $this->serverService->updatePool($pool->getId(), [
            'strategy' => ServerPool::STRATEGY_ROUND_ROBIN,
        ]);
        $this->assertSame(ServerPool::STRATEGY_ROUND_ROBIN, $updated->getStrategy());

        // Invalid strategy throws ValidationException
        $this->expectException(ValidationException::class);
        $this->serverService->updatePool($pool->getId(), [
            'strategy' => 'invalid_strategy',
        ]);
    }

    public function testServerCapacityCalculationsAndHeadroom(): void
    {
        $capacity = new ServerCapacity(
            maxAccounts: 100,
            usedAccounts: 40,
            diskCapacityMb: 100000,
            diskUsedMb: 50000,
            bandwidthCapacityMb: 200000,
            bandwidthUsedMb: 100000
        );

        $this->assertSame(60, $capacity->getAvailableAccounts());
        $this->assertSame(40.0, $capacity->getAccountUsagePercent());
        $this->assertSame(50.0, $capacity->getDiskUsagePercent());
        $this->assertSame(50.0, $capacity->getBandwidthUsagePercent());

        $this->assertTrue($capacity->hasAccountHeadroom(10));
        $this->assertFalse($capacity->hasAccountHeadroom(70));

        $this->assertTrue($capacity->hasDiskHeadroom(20000));
        $this->assertFalse($capacity->hasDiskHeadroom(60000));

        $this->assertTrue($capacity->hasBandwidthHeadroom(50000));
        $this->assertFalse($capacity->hasBandwidthHeadroom(120000));

        $this->assertTrue($capacity->canAcceptPlacement(10000, 20000));
        $this->assertFalse($capacity->canAcceptPlacement(60000, 10000));

        // Load score: (0.40 * 0.6) + (0.50 * 0.25) + (0.50 * 0.15) = 0.24 + 0.125 + 0.075 = 0.44
        $this->assertSame(0.44, $capacity->calculateLoadScore());

        // Increment & Decrement
        $incremented = $capacity->increment(5, 5000, 10000);
        $this->assertSame(45, $incremented->getUsedAccounts());
        $this->assertSame(55000, $incremented->getDiskUsedMb());

        $decremented = $incremented->decrement(10, 10000, 20000);
        $this->assertSame(35, $decremented->getUsedAccounts());
        $this->assertSame(45000, $decremented->getDiskUsedMb());
    }

    public function testServerEntityAndConnectionDtoConversion(): void
    {
        $server = new Server(
            id: 1,
            name: 'cPanel EU Node 1',
            hostname: 'cpanel-eu-01.colezahost.net',
            ipAddress: '192.168.10.1',
            providerSlug: 'cpanel',
            serverPoolId: 10,
            locationId: 5,
            status: Server::STATUS_ACTIVE,
            capacity: new ServerCapacity(maxAccounts: 50, usedAccounts: 10),
            port: 2087,
            secure: true,
            authType: 'api_token',
            authSecret: 'whm_token_super_secret_9988'
        );

        $this->assertTrue($server->isActive());
        $this->assertFalse($server->isMaintenance());
        $this->assertFalse($server->isFull());
        $this->assertTrue($server->canAcceptPlacement());

        // Secret is masked in toArray
        $arr = $server->toArray();
        $this->assertNotSame('whm_token_super_secret_9988', $arr['auth_secret']);
        $this->assertSame($server->getMaskedSecret(), $arr['auth_secret']);

        // Convert to ServerConnectionDto for provider usage
        $conn = $server->toServerConnectionDto();
        $this->assertSame(1, $conn->getServerId());
        $this->assertSame('cpanel-eu-01.colezahost.net', $conn->getHostname());
        $this->assertSame('192.168.10.1', $conn->getIpAddress());
        $this->assertSame(2087, $conn->getPort());
        $this->assertSame('whm_token_super_secret_9988', $conn->getAuthSecret());
    }

    public function testServerCreationAndListing(): void
    {
        $loc = $this->serverService->createLocation([
            'name' => 'London DC',
            'slug' => 'lon-01',
            'country_code' => 'gb',
            'city' => 'London',
        ]);

        $pool = $this->serverService->createPool([
            'name' => 'DirectAdmin Pool',
            'slug' => 'da-pool',
            'provider_slug' => 'directadmin',
            'location_id' => $loc->getId(),
        ]);

        $server = $this->serverService->createServer([
            'name' => 'DA Node 1',
            'hostname' => 'da01.colezahost.net',
            'ip_address' => '10.0.0.5',
            'provider_slug' => 'directadmin',
            'server_pool_id' => $pool->getId(),
            'location_id' => $loc->getId(),
            'max_accounts' => 200,
            'disk_capacity_mb' => 500000,
            'bandwidth_capacity_mb' => 2000000,
            'port' => 2222,
            'auth_type' => 'password',
            'auth_secret' => 'admin_secret_pass',
            'assigned_ip_pool' => ['10.0.0.10', '10.0.0.11'],
        ]);

        $this->assertSame('DA Node 1', $server->getName());
        $this->assertSame('da01.colezahost.net', $server->getHostname());
        $this->assertSame('10.0.0.5', $server->getIpAddress());
        $this->assertSame(200, $server->getCapacity()->getMaxAccounts());
        $this->assertSame(['10.0.0.10', '10.0.0.11'], $server->getAssignedIpPool());

        // Filter servers by pool and location
        $servers = $this->serverService->listServers(poolId: $pool->getId(), locationId: $loc->getId());
        $this->assertCount(1, $servers);
        $this->assertSame($server->getId(), $servers[0]->getId());
    }

    public function testCapacityAllocationAndAutomaticFullStatusTransition(): void
    {
        $server = $this->serverService->createServer([
            'name' => 'Small Node',
            'hostname' => 'small01.colezahost.net',
            'ip_address' => '10.0.0.20',
            'provider_slug' => 'cpanel',
            'max_accounts' => 3,
            'status' => Server::STATUS_ACTIVE,
        ]);

        $this->assertTrue($server->isActive());
        $this->assertFalse($server->isFull());

        // Add 2 accounts -> still active (2/3)
        $s1 = $this->serverService->incrementAccountCount($server->getId(), 2, 2000, 5000);
        $this->assertSame(2, $s1->getCapacity()->getUsedAccounts());
        $this->assertTrue($s1->isActive());
        $this->assertFalse($s1->isFull());

        // Add 1 more account -> reaches capacity (3/3), status becomes full automatically
        $s2 = $this->serverService->incrementAccountCount($server->getId(), 1, 1000, 2000);
        $this->assertSame(3, $s2->getCapacity()->getUsedAccounts());
        $this->assertSame(Server::STATUS_FULL, $s2->getStatus());
        $this->assertTrue($s2->isFull());
        $this->assertFalse($s2->canAcceptPlacement());

        // Decrement 1 account -> status returns to active automatically
        $s3 = $this->serverService->decrementAccountCount($server->getId(), 1, 1000, 2000);
        $this->assertSame(2, $s3->getCapacity()->getUsedAccounts());
        $this->assertSame(Server::STATUS_ACTIVE, $s3->getStatus());
        $this->assertTrue($s3->isActive());
        $this->assertTrue($s3->canAcceptPlacement());
    }

    public function testServerUsageUpdateAndCapacityCheck(): void
    {
        $server = $this->serverService->createServer([
            'name' => 'Quota Node',
            'hostname' => 'quota01.colezahost.net',
            'ip_address' => '10.0.0.30',
            'provider_slug' => 'cpanel',
            'max_accounts' => 10,
            'disk_capacity_mb' => 20000,
            'bandwidth_capacity_mb' => 50000,
        ]);

        $this->assertTrue($this->serverService->checkCapacity($server->getId(), 5000, 10000));

        // Update usage pushing disk to near capacity
        $this->serverService->updateServerUsage(
            serverId: $server->getId(),
            usedAccounts: 5,
            diskUsedMb: 18000,
            bandwidthUsedMb: 20000
        );

        // Required 5000 MB disk exceeds remaining (20000 - 18000 = 2000 MB)
        $this->assertFalse($this->serverService->checkCapacity($server->getId(), 5000, 5000));
        // Required 1000 MB disk fits
        $this->assertTrue($this->serverService->checkCapacity($server->getId(), 1000, 5000));
    }
}
