<?php

declare(strict_types=1);

namespace Tests\Unit\Servers;

use Coleza\Domain\Servers\Entities\Location;
use Coleza\Domain\Servers\Entities\Server;
use Coleza\Domain\Servers\Entities\ServerCapacity;
use Coleza\Domain\Servers\Entities\ServerPool;
use Coleza\Domain\Servers\Placement\DefaultServerHealthChecker;
use Coleza\Domain\Servers\Placement\PlacementDecision;
use Coleza\Domain\Servers\Placement\PlacementEngine;
use Coleza\Domain\Servers\Placement\PlacementRequest;
use Coleza\Domain\Servers\Placement\Strategies\RoundRobinStrategy;
use Coleza\Domain\Servers\Services\ServerService;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class PlacementEngineTest extends TestCase
{
    private Connection $db;
    private ServerService $serverService;
    private DefaultServerHealthChecker $healthChecker;
    private PlacementEngine $engine;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->serverService = new ServerService($this->db);
        $this->serverService->ensureTables();

        $this->healthChecker = new DefaultServerHealthChecker();
        $this->engine = new PlacementEngine($this->serverService, $this->healthChecker);
    }

    public function testLeastLoadedStrategySelectsLowestLoadedServer(): void
    {
        $pool = $this->serverService->createPool([
            'name' => 'cPanel Least Loaded Pool',
            'slug' => 'cpanel-least-loaded',
            'provider_slug' => 'cpanel',
            'strategy' => ServerPool::STRATEGY_LEAST_LOADED,
        ]);

        // Server A: 80% used
        $sA = $this->serverService->createServer([
            'name' => 'Node A',
            'hostname' => 'node-a.test',
            'ip_address' => '10.0.0.1',
            'provider_slug' => 'cpanel',
            'server_pool_id' => $pool->getId(),
            'max_accounts' => 100,
            'used_accounts' => 80,
        ]);

        // Server B: 15% used
        $sB = $this->serverService->createServer([
            'name' => 'Node B',
            'hostname' => 'node-b.test',
            'ip_address' => '10.0.0.2',
            'provider_slug' => 'cpanel',
            'server_pool_id' => $pool->getId(),
            'max_accounts' => 100,
            'used_accounts' => 15,
        ]);

        // Server C: 50% used
        $sC = $this->serverService->createServer([
            'name' => 'Node C',
            'hostname' => 'node-c.test',
            'ip_address' => '10.0.0.3',
            'provider_slug' => 'cpanel',
            'server_pool_id' => $pool->getId(),
            'max_accounts' => 100,
            'used_accounts' => 50,
        ]);

        $request = new PlacementRequest(serverPoolId: $pool->getId());
        $decision = $this->engine->selectServer($request);

        $this->assertTrue($decision->isSuccessful());
        $this->assertSame($sB->getId(), $decision->getSelectedServer()?->getId());
        $this->assertSame(ServerPool::STRATEGY_LEAST_LOADED, $decision->getStrategyUsed());
    }

    public function testFillFirstStrategySelectsMostLoadedServerWithHeadroom(): void
    {
        $pool = $this->serverService->createPool([
            'name' => 'Fill First Pool',
            'slug' => 'fill-first-pool',
            'provider_slug' => 'cpanel',
            'strategy' => ServerPool::STRATEGY_FILL_FIRST,
        ]);

        // Server A: 10/100
        $sA = $this->serverService->createServer([
            'name' => 'Node 1',
            'hostname' => 'node-1.test',
            'ip_address' => '10.0.1.1',
            'provider_slug' => 'cpanel',
            'server_pool_id' => $pool->getId(),
            'max_accounts' => 100,
            'used_accounts' => 10,
        ]);

        // Server B: 85/100
        $sB = $this->serverService->createServer([
            'name' => 'Node 2',
            'hostname' => 'node-2.test',
            'ip_address' => '10.0.1.2',
            'provider_slug' => 'cpanel',
            'server_pool_id' => $pool->getId(),
            'max_accounts' => 100,
            'used_accounts' => 85,
        ]);

        $request = new PlacementRequest(serverPoolId: $pool->getId());
        $decision = $this->engine->selectServer($request);

        $this->assertTrue($decision->isSuccessful());
        $this->assertSame($sB->getId(), $decision->getSelectedServer()?->getId());
        $this->assertSame(ServerPool::STRATEGY_FILL_FIRST, $decision->getStrategyUsed());
    }

    public function testRoundRobinStrategyDistributesSequentially(): void
    {
        RoundRobinStrategy::resetCounter();

        $pool = $this->serverService->createPool([
            'name' => 'Round Robin Pool',
            'slug' => 'rr-pool',
            'provider_slug' => 'directadmin',
            'strategy' => ServerPool::STRATEGY_ROUND_ROBIN,
        ]);

        $s1 = $this->serverService->createServer([
            'name' => 'RR 1',
            'hostname' => 'rr1.test',
            'ip_address' => '10.0.2.1',
            'provider_slug' => 'directadmin',
            'server_pool_id' => $pool->getId(),
        ]);

        $s2 = $this->serverService->createServer([
            'name' => 'RR 2',
            'hostname' => 'rr2.test',
            'ip_address' => '10.0.2.2',
            'provider_slug' => 'directadmin',
            'server_pool_id' => $pool->getId(),
        ]);

        $s3 = $this->serverService->createServer([
            'name' => 'RR 3',
            'hostname' => 'rr3.test',
            'ip_address' => '10.0.2.3',
            'provider_slug' => 'directadmin',
            'server_pool_id' => $pool->getId(),
        ]);

        $request = new PlacementRequest(serverPoolId: $pool->getId());

        $d1 = $this->engine->selectServer($request);
        $d2 = $this->engine->selectServer($request);
        $d3 = $this->engine->selectServer($request);
        $d4 = $this->engine->selectServer($request);

        $this->assertSame($s1->getId(), $d1->getSelectedServer()?->getId());
        $this->assertSame($s2->getId(), $d2->getSelectedServer()?->getId());
        $this->assertSame($s3->getId(), $d3->getSelectedServer()?->getId());
        $this->assertSame($s1->getId(), $d4->getSelectedServer()?->getId());
    }

    public function testHealthCheckFiltersUnhealthyOrMaintenanceServers(): void
    {
        $pool = $this->serverService->createPool([
            'name' => 'Health Test Pool',
            'slug' => 'health-pool',
            'provider_slug' => 'cpanel',
        ]);

        // Healthy server
        $healthyServer = $this->serverService->createServer([
            'name' => 'Healthy Node',
            'hostname' => 'healthy.test',
            'ip_address' => '10.0.3.1',
            'provider_slug' => 'cpanel',
            'server_pool_id' => $pool->getId(),
            'status' => Server::STATUS_ACTIVE,
        ]);

        // Server under maintenance
        $maintServer = $this->serverService->createServer([
            'name' => 'Maint Node',
            'hostname' => 'maint.test',
            'ip_address' => '10.0.3.2',
            'provider_slug' => 'cpanel',
            'server_pool_id' => $pool->getId(),
            'status' => Server::STATUS_MAINTENANCE,
        ]);

        // Server simulated offline
        $offlineServer = $this->serverService->createServer([
            'name' => 'Offline Node',
            'hostname' => 'offline.test',
            'ip_address' => '10.0.3.3',
            'provider_slug' => 'cpanel',
            'server_pool_id' => $pool->getId(),
            'status' => Server::STATUS_ACTIVE,
        ]);
        $this->healthChecker->setSimulatedHealth($offlineServer->getId(), false);

        $decision = $this->engine->selectServer(new PlacementRequest(serverPoolId: $pool->getId()));

        $this->assertTrue($decision->isSuccessful());
        $this->assertSame($healthyServer->getId(), $decision->getSelectedServer()?->getId());
        $this->assertArrayHasKey($maintServer->getId(), $decision->getRejectionReasons());
        $this->assertArrayHasKey($offlineServer->getId(), $decision->getRejectionReasons());
    }

    public function testCapacityFiltersInsufficientDiskOrBandwidth(): void
    {
        $pool = $this->serverService->createPool([
            'name' => 'Disk Quota Pool',
            'slug' => 'quota-pool',
            'provider_slug' => 'cpanel',
        ]);

        // Server with 10 GB disk remaining (50 GB total, 40 GB used)
        $smallServer = $this->serverService->createServer([
            'name' => 'Small Disk Node',
            'hostname' => 'small-disk.test',
            'ip_address' => '10.0.4.1',
            'provider_slug' => 'cpanel',
            'server_pool_id' => $pool->getId(),
            'disk_capacity_mb' => 50000,
            'disk_used_mb' => 40000,
        ]);

        // Server with 80 GB disk remaining (100 GB total, 20 GB used)
        $largeServer = $this->serverService->createServer([
            'name' => 'Large Disk Node',
            'hostname' => 'large-disk.test',
            'ip_address' => '10.0.4.2',
            'provider_slug' => 'cpanel',
            'server_pool_id' => $pool->getId(),
            'disk_capacity_mb' => 100000,
            'disk_used_mb' => 20000,
        ]);

        // Request requiring 30 GB disk (30,000 MB)
        $request = new PlacementRequest(
            serverPoolId: $pool->getId(),
            requiredDiskMb: 30000
        );

        $decision = $this->engine->selectServer($request);
        $this->assertTrue($decision->isSuccessful());
        $this->assertSame($largeServer->getId(), $decision->getSelectedServer()?->getId());
        $this->assertArrayHasKey($smallServer->getId(), $decision->getRejectionReasons());
    }

    public function testDedicatedIpRequirementFiltering(): void
    {
        $pool = $this->serverService->createPool([
            'name' => 'IP Pool Test',
            'slug' => 'ip-pool',
            'provider_slug' => 'cpanel',
        ]);

        // Node without IP pool
        $noIpNode = $this->serverService->createServer([
            'name' => 'No Dedicated IP Node',
            'hostname' => 'shared-only.test',
            'ip_address' => '10.0.5.1',
            'provider_slug' => 'cpanel',
            'server_pool_id' => $pool->getId(),
            'assigned_ip_pool' => [],
        ]);

        // Node with IP pool
        $withIpNode = $this->serverService->createServer([
            'name' => 'With Dedicated IP Node',
            'hostname' => 'dedicated-ips.test',
            'ip_address' => '10.0.5.2',
            'provider_slug' => 'cpanel',
            'server_pool_id' => $pool->getId(),
            'assigned_ip_pool' => ['192.168.100.10', '192.168.100.11'],
        ]);

        $request = new PlacementRequest(
            serverPoolId: $pool->getId(),
            requiredDedicatedIp: true
        );

        $decision = $this->engine->selectServer($request);
        $this->assertTrue($decision->isSuccessful());
        $this->assertSame($withIpNode->getId(), $decision->getSelectedServer()?->getId());
        $this->assertArrayHasKey($noIpNode->getId(), $decision->getRejectionReasons());
    }

    public function testPreferredServerPriorityAffinity(): void
    {
        $pool = $this->serverService->createPool([
            'name' => 'Priority Pool',
            'slug' => 'priority-pool',
            'provider_slug' => 'cpanel',
        ]);

        $s1 = $this->serverService->createServer([
            'name' => 'Node 1',
            'hostname' => 'p1.test',
            'ip_address' => '10.0.6.1',
            'provider_slug' => 'cpanel',
            'server_pool_id' => $pool->getId(),
            'used_accounts' => 5,
        ]);

        $s2 = $this->serverService->createServer([
            'name' => 'Node 2',
            'hostname' => 'p2.test',
            'ip_address' => '10.0.6.2',
            'provider_slug' => 'cpanel',
            'server_pool_id' => $pool->getId(),
            'used_accounts' => 30, // Higher load
        ]);

        // Request with affinity to s2
        $request = new PlacementRequest(
            serverPoolId: $pool->getId(),
            preferredServerId: $s2->getId()
        );

        $decision = $this->engine->selectServer($request);
        $this->assertTrue($decision->isSuccessful());
        $this->assertSame($s2->getId(), $decision->getSelectedServer()?->getId());
        $this->assertSame('preferred_priority', $decision->getStrategyUsed());
    }

    public function testAllCandidatesRejectedDecision(): void
    {
        $pool = $this->serverService->createPool([
            'name' => 'Exhausted Pool',
            'slug' => 'exhausted-pool',
            'provider_slug' => 'cpanel',
        ]);

        $fullServer = $this->serverService->createServer([
            'name' => 'Full Node',
            'hostname' => 'full.test',
            'ip_address' => '10.0.7.1',
            'provider_slug' => 'cpanel',
            'server_pool_id' => $pool->getId(),
            'max_accounts' => 5,
            'used_accounts' => 5,
            'status' => Server::STATUS_FULL,
        ]);

        $decision = $this->engine->selectServer(new PlacementRequest(serverPoolId: $pool->getId()));

        $this->assertFalse($decision->isSuccessful());
        $this->assertNull($decision->getSelectedServer());
        $this->assertSame(1, $decision->getCandidatesEvaluated());
        $this->assertArrayHasKey($fullServer->getId(), $decision->getRejectionReasons());
    }

    public function testInactiveOrMissingPoolRejection(): void
    {
        $decisionMissing = $this->engine->selectServer(new PlacementRequest(serverPoolId: 9999));
        $this->assertFalse($decisionMissing->isSuccessful());
        $this->assertStringContainsString('not found', $decisionMissing->getMessage());

        $inactivePool = $this->serverService->createPool([
            'name' => 'Inactive Pool',
            'slug' => 'inactive-pool',
            'provider_slug' => 'cpanel',
            'is_active' => 0,
        ]);

        $decisionInactive = $this->engine->selectServer(new PlacementRequest(serverPoolId: $inactivePool->getId()));
        $this->assertFalse($decisionInactive->isSuccessful());
        $this->assertStringContainsString('inactive', $decisionInactive->getMessage());
    }
}
