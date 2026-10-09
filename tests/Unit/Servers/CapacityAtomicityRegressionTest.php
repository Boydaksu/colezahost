<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Servers;

use Coleza\Domain\Servers\Services\ServerService;
use Coleza\Domain\Servers\Capacity\Services\CapacityReservationService;
use Coleza\Domain\Servers\Capacity\Exceptions\ReservationException;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

final class CapacityAtomicityRegressionTest extends TestCase
{
    private Connection $db;
    private ServerService $servers;
    private CapacityReservationService $reservations;
    private int $server;

    protected function setUp(): void
    {
        $this->db = new Connection(new \PDO('sqlite::memory:'));
        $this->servers = new ServerService($this->db);
        $this->servers->ensureTables();
        $this->reservations = new CapacityReservationService($this->db, $this->servers);
        $this->reservations->ensureTables();
        $this->server = (int) $this->servers->createServer(['name' => 'Test node', 'hostname' => 'node.example.test',
            'ip_address' => '127.0.0.1', 'provider_slug' => 'cpanel', 'max_accounts' => 2,
            'disk_capacity_mb' => 1000, 'bandwidth_capacity_mb' => 1000])->getId();
    }

    public function testFailedReservationInsertDoesNotConsumeCapacity(): void
    {
        $this->db->statement("CREATE TRIGGER fail_reservation BEFORE INSERT ON capacity_reservations BEGIN SELECT RAISE(ABORT, 'reservation failure'); END");
        try { $this->reservations->reserve($this->server); self::fail('Failure must propagate.'); } catch (\PDOException) {}
        self::assertSame(0, $this->servers->findServerById($this->server)->getCapacity()->getUsedAccounts());
    }

    public function testFailedReleaseKeepsCapacityAndReservationTogether(): void
    {
        $reservation = $this->reservations->reserve($this->server);
        $this->db->statement("CREATE TRIGGER fail_release BEFORE UPDATE ON capacity_reservations WHEN NEW.status = 'released' BEGIN SELECT RAISE(ABORT, 'release failure'); END");
        try { $this->reservations->release($reservation->getToken()); self::fail('Failure must propagate.'); } catch (\PDOException) {}
        self::assertSame(1, $this->servers->findServerById($this->server)->getCapacity()->getUsedAccounts());
        self::assertTrue($this->reservations->findReservationByToken($reservation->getToken())->isReserved());
    }

    public function testDirectCounterCannotExceedAccountLimit(): void
    {
        $this->expectException(ValidationException::class);
        $this->servers->incrementAccountCount($this->server, 3);
    }

    public function testNegativeReservationCannotCreateCapacity(): void
    {
        $this->expectException(ReservationException::class);
        $this->reservations->reserve($this->server, -1);
    }

    public function testRepeatedServiceReservationReusesOneAllocation(): void
    {
        $first = $this->reservations->reserve($this->server, serviceId: 77);
        $second = $this->reservations->reserve($this->server, serviceId: 77);
        self::assertSame($first->getToken(), $second->getToken());
        self::assertSame(1, $this->servers->findServerById($this->server)->getCapacity()->getUsedAccounts());
    }

    public function testExpiredCommitReleasesCapacityEvenThoughItThrows(): void
    {
        $reservation = $this->reservations->reserve($this->server);
        $this->db->statement('UPDATE capacity_reservations SET expires_at = "2000-01-01 00:00:00"');
        try { $this->reservations->commit($reservation->getToken()); self::fail('Expired commit must fail.'); } catch (ReservationException) {}
        self::assertSame(0, $this->servers->findServerById($this->server)->getCapacity()->getUsedAccounts());
    }

    public function testTelemetryCannotEraseActiveReservation(): void
    {
        $this->reservations->reserve($this->server);
        $this->expectException(ValidationException::class);
        $this->servers->updateServerUsage($this->server, 0, 0, 0);
    }

    public function testOrderItemRetryAfterServiceCommitStillUsesOriginalReservation(): void
    {
        $first = $this->reservations->reserve($this->server, orderItemId: 123);
        $this->reservations->commit($first->getToken(), 77);
        self::assertSame($first->getToken(), $this->reservations->reserve($this->server, orderItemId: 123)->getToken());
        self::assertSame(1, $this->servers->findServerById($this->server)->getCapacity()->getUsedAccounts());
    }

    public function testDirectDecrementCannotFreeAnActiveReservation(): void
    {
        $this->reservations->reserve($this->server);
        try { $this->servers->decrementAccountCount($this->server); self::fail('Active allocation must be protected.'); }
        catch (ValidationException) {}
        self::assertSame(1, $this->servers->findServerById($this->server)->getCapacity()->getUsedAccounts());
    }

    public function testCounterFailureRollsBackReservationTerminalStatus(): void
    {
        $res = $this->reservations->reserve($this->server);
        $this->db->statement("CREATE TRIGGER fail_counter BEFORE UPDATE ON servers WHEN NEW.used_accounts < OLD.used_accounts BEGIN SELECT RAISE(ABORT, 'counter failure'); END");
        try { $this->reservations->release($res->getToken()); self::fail('Counter failure must propagate.'); } catch (\PDOException) {}
        self::assertTrue($this->reservations->findReservationByToken($res->getToken())->isReserved());
        self::assertSame(1, $this->servers->findServerById($this->server)->getCapacity()->getUsedAccounts());
    }

    public function testReservedServiceCannotBeReassignedDuringCommit(): void
    {
        $res = $this->reservations->reserve($this->server, serviceId: 77);
        try { $this->reservations->commit($res->getToken(), 88); self::fail('Service reassignment must fail.'); }
        catch (ReservationException) {}
        self::assertSame(77, $this->reservations->findReservationByToken($res->getToken())->getServiceId());
        self::assertTrue($this->reservations->findReservationByToken($res->getToken())->isReserved());
    }
}
