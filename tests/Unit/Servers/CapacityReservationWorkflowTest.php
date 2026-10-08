<?php

declare(strict_types=1);

namespace Tests\Unit\Servers;

use Coleza\Domain\Servers\Capacity\Entities\CapacityReservation;
use Coleza\Domain\Servers\Capacity\Exceptions\CapacityExceededException;
use Coleza\Domain\Servers\Capacity\Exceptions\ReservationException;
use Coleza\Domain\Servers\Capacity\Services\CapacityReservationService;
use Coleza\Domain\Servers\Entities\Server;
use Coleza\Domain\Servers\Services\ServerService;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class CapacityReservationWorkflowTest extends TestCase
{
    private Connection $db;
    private ServerService $serverService;
    private CapacityReservationService $reservationService;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->serverService = new ServerService($this->db);
        $this->serverService->ensureTables();

        $this->reservationService = new CapacityReservationService($this->db, $this->serverService);
        $this->reservationService->ensureTables();
    }

    public function testReserveCapacitySuccessfullyIncrementsServerUsage(): void
    {
        $server = $this->serverService->createServer([
            'name' => 'cPanel Res Node',
            'hostname' => 'res01.test',
            'ip_address' => '10.0.0.50',
            'provider_slug' => 'cpanel',
            'max_accounts' => 100,
            'used_accounts' => 10,
            'disk_capacity_mb' => 500000,
            'bandwidth_capacity_mb' => 1000000,
        ]);

        $res = $this->reservationService->reserve(
            serverId: $server->getId(),
            accountsCount: 1,
            diskMb: 5000,
            bandwidthMb: 15000,
            ttlSeconds: 600,
            serviceId: 101,
            orderId: 201
        );

        $this->assertSame(CapacityReservation::STATUS_RESERVED, $res->getStatus());
        $this->assertTrue($res->isReserved());
        $this->assertSame($server->getId(), $res->getServerId());
        $this->assertSame(1, $res->getAccountsCount());
        $this->assertSame(5000, $res->getDiskMb());
        $this->assertSame(15000, $res->getBandwidthMb());
        $this->assertSame(101, $res->getServiceId());
        $this->assertSame(201, $res->getOrderId());
        $this->assertStringStartsWith('RES-', $res->getToken());

        // Verify server usage incremented
        $refreshed = $this->serverService->findServerById($server->getId());
        $this->assertSame(11, $refreshed->getCapacity()->getUsedAccounts());
        $this->assertSame(5000, $refreshed->getCapacity()->getDiskUsedMb());
        $this->assertSame(15000, $refreshed->getCapacity()->getBandwidthUsedMb());
    }

    public function testReserveFailsWhenCapacityExceeded(): void
    {
        $server = $this->serverService->createServer([
            'name' => 'Small Limited Node',
            'hostname' => 'limited01.test',
            'ip_address' => '10.0.0.51',
            'provider_slug' => 'cpanel',
            'max_accounts' => 5,
            'used_accounts' => 4,
            'disk_capacity_mb' => 10000,
            'disk_used_mb' => 9000,
        ]);

        // Requesting 2 accounts when only 1 is available (4/5)
        $this->expectException(CapacityExceededException::class);
        $this->reservationService->reserve(
            serverId: $server->getId(),
            accountsCount: 2
        );

        // Server usage remains untouched
        $refreshed = $this->serverService->findServerById($server->getId());
        $this->assertSame(4, $refreshed->getCapacity()->getUsedAccounts());
    }

    public function testCommitReservationTransitionsToCommitted(): void
    {
        $server = $this->serverService->createServer([
            'name' => 'Commit Node',
            'hostname' => 'commit01.test',
            'ip_address' => '10.0.0.52',
            'provider_slug' => 'cpanel',
        ]);

        $res = $this->reservationService->reserve(
            serverId: $server->getId(),
            accountsCount: 1,
            diskMb: 2000
        );

        $this->assertTrue($res->isReserved());

        $committed = $this->reservationService->commit($res->getToken(), serviceId: 999);
        $this->assertSame(CapacityReservation::STATUS_COMMITTED, $committed->getStatus());
        $this->assertTrue($committed->isCommitted());
        $this->assertSame(999, $committed->getServiceId());
        $this->assertNotNull($committed->getCommittedAt());

        // Double commit is idempotent
        $idempotent = $this->reservationService->commit($res->getToken());
        $this->assertSame($committed->getId(), $idempotent->getId());
    }

    public function testCommitExpiredReservationThrowsExceptionAndReleasesCapacity(): void
    {
        $server = $this->serverService->createServer([
            'name' => 'Expiry Node',
            'hostname' => 'expiry01.test',
            'ip_address' => '10.0.0.53',
            'provider_slug' => 'cpanel',
            'max_accounts' => 10,
            'used_accounts' => 0,
        ]);

        $res = $this->reservationService->reserve(
            serverId: $server->getId(),
            accountsCount: 1,
            diskMb: 1000,
            ttlSeconds: -10 // already expired
        );

        $this->assertTrue($res->hasExpired());

        $this->expectException(ReservationException::class);
        try {
            $this->reservationService->commit($res->getToken());
        } finally {
            // Verify capacity was refunded to server
            $refreshed = $this->serverService->findServerById($server->getId());
            $this->assertSame(0, $refreshed->getCapacity()->getUsedAccounts());
        }
    }

    public function testReleaseReservationDecrementsServerQuota(): void
    {
        $server = $this->serverService->createServer([
            'name' => 'Release Node',
            'hostname' => 'release01.test',
            'ip_address' => '10.0.0.54',
            'provider_slug' => 'cpanel',
            'max_accounts' => 10,
            'used_accounts' => 2,
            'disk_capacity_mb' => 20000,
            'disk_used_mb' => 5000,
        ]);

        $res = $this->reservationService->reserve(
            serverId: $server->getId(),
            accountsCount: 1,
            diskMb: 3000
        );

        $sAfterRes = $this->serverService->findServerById($server->getId());
        $this->assertSame(3, $sAfterRes->getCapacity()->getUsedAccounts());
        $this->assertSame(8000, $sAfterRes->getCapacity()->getDiskUsedMb());

        $released = $this->reservationService->release($res->getToken(), 'order_checkout_cancelled');
        $this->assertSame(CapacityReservation::STATUS_RELEASED, $released->getStatus());
        $this->assertTrue($released->isReleased());
        $this->assertSame('order_checkout_cancelled', $released->getReleaseReason());
        $this->assertNotNull($released->getReleasedAt());

        // Verify server quota returned
        $sAfterRel = $this->serverService->findServerById($server->getId());
        $this->assertSame(2, $sAfterRel->getCapacity()->getUsedAccounts());
        $this->assertSame(5000, $sAfterRel->getCapacity()->getDiskUsedMb());
    }

    public function testExpireStaleReservationsBatchWorker(): void
    {
        $server = $this->serverService->createServer([
            'name' => 'Batch Expiry Node',
            'hostname' => 'batch-exp01.test',
            'ip_address' => '10.0.0.55',
            'provider_slug' => 'cpanel',
            'max_accounts' => 20,
            'used_accounts' => 0,
        ]);

        // Stale 1 (expired)
        $r1 = $this->reservationService->reserve($server->getId(), accountsCount: 1, ttlSeconds: -60);
        // Stale 2 (expired)
        $r2 = $this->reservationService->reserve($server->getId(), accountsCount: 1, ttlSeconds: -30);
        // Active 3 (valid for 10 minutes)
        $r3 = $this->reservationService->reserve($server->getId(), accountsCount: 1, ttlSeconds: 600);

        $sWithAll = $this->serverService->findServerById($server->getId());
        $this->assertSame(3, $sWithAll->getCapacity()->getUsedAccounts());

        $expired = $this->reservationService->expireStaleReservations();
        $this->assertCount(2, $expired);

        $tokens = array_map(fn ($r) => $r->getToken(), $expired);
        $this->assertContains($r1->getToken(), $tokens);
        $this->assertContains($r2->getToken(), $tokens);

        // Server only has r3 retained (used_accounts = 1)
        $sAfterSweep = $this->serverService->findServerById($server->getId());
        $this->assertSame(1, $sAfterSweep->getCapacity()->getUsedAccounts());

        $activeR3 = $this->reservationService->findReservationByToken($r3->getToken());
        $this->assertTrue($activeR3->isReserved());
    }

    public function testCommitAlreadyReleasedOrExpiredReservationThrowsException(): void
    {
        $server = $this->serverService->createServer([
            'name' => 'Invalid State Node',
            'hostname' => 'invalid-state01.test',
            'ip_address' => '10.0.0.56',
            'provider_slug' => 'cpanel',
        ]);

        $res = $this->reservationService->reserve($server->getId());
        $this->reservationService->release($res->getToken(), 'cancelled');

        $this->expectException(ReservationException::class);
        $this->reservationService->commit($res->getToken());
    }
}
