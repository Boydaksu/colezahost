<?php

declare(strict_types=1);

namespace Coleza\Tests\MariaDb;

use Coleza\Domain\Servers\Services\ServerService;
use Coleza\Domain\Servers\Capacity\Services\CapacityReservationService;
use Coleza\Domain\Servers\Capacity\Services\CapacityScopeSchema;
use Coleza\Domain\Servers\Capacity\Entities\CapacityReservation;
use Coleza\Domain\Notifications\Center\NotificationCenterService;
use Coleza\Foundation\Database\Migrator;
use Coleza\Tests\Support\MariaDbTestCase;

final class CapacityAndNotificationTest extends MariaDbTestCase
{
    private ServerService $servers;
    private CapacityReservationService $reservations;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db))->migrate(dirname(__DIR__, 2) . '/database/migrations');
        $this->servers = new ServerService($this->db);
        $this->reservations = new CapacityReservationService($this->db, $this->servers);
    }

    private function server(int $max = 2): int
    {
        return (int) $this->servers->createServer(['name' => 'Test node', 'hostname' => 'node.example.test',
            'ip_address' => '127.0.0.1', 'provider_slug' => 'cpanel', 'max_accounts' => $max,
            'disk_capacity_mb' => 1000, 'bandwidth_capacity_mb' => 1000])->getId();
    }

    public function testParallelReservationsCannotOversubscribeOneAccount(): void
    {
        $server = $this->server(1);
        $results = $this->runParallel('reserve', $server);
        self::assertSame(1, count(array_filter($results, static fn ($r) => $r['status'] === 'ok')));
        self::assertSame(1, $this->servers->findServerById($server)->getCapacity()->getUsedAccounts());
    }

    public function testParallelReservationsRespectDiskAndBandwidth(): void
    {
        $server = $this->server(10);
        $results = $this->runParallel('reserveResource', $server);
        self::assertSame(1, count(array_filter($results, static fn ($r) => $r['status'] === 'ok')));
        self::assertSame(600, $this->servers->findServerById($server)->getCapacity()->getDiskUsedMb());
        self::assertSame(600, $this->servers->findServerById($server)->getCapacity()->getBandwidthUsedMb());
    }

    public function testParallelServiceRetriesReuseOneReservation(): void
    {
        $server = $this->server();
        $results = $this->runParallel('sameScope', $server);
        self::assertSame(['ok', 'ok', 'ok', 'ok'], array_column($results, 'status'));
        self::assertCount(1, array_unique(array_column($results, 'token')));
        self::assertSame(1, $this->servers->findServerById($server)->getCapacity()->getUsedAccounts());
    }

    public function testParallelReleasesReturnOnlyTheirOwnQuotaOnce(): void
    {
        $server = $this->server(4);
        $first = $this->reservations->reserve($server);
        $second = $this->reservations->reserve($server);
        $this->runParallel('releaseCapacity', $server, $first->getToken());
        self::assertSame(1, $this->servers->findServerById($server)->getCapacity()->getUsedAccounts());
        self::assertTrue($this->reservations->findReservationByToken($second->getToken())->isReserved());
    }

    public function testCommitAndExpiryRacePreservesTerminalStatusAndQuota(): void
    {
        $server = $this->server();
        $res = $this->reservations->reserve($server);
        $this->runParallel('commitExpire', $server, $res->getToken());
        $status = $this->reservations->findReservationByToken($res->getToken())->getStatus();
        self::assertContains($status, [CapacityReservation::STATUS_COMMITTED, CapacityReservation::STATUS_EXPIRED]);
        self::assertSame($status === CapacityReservation::STATUS_COMMITTED ? 1 : 0,
            $this->servers->findServerById($server)->getCapacity()->getUsedAccounts());
    }

    public function testNotificationPreferencesAndInboxPersistWithNativePreparedQueries(): void
    {
        $this->root->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
        $center = new NotificationCenterService($this->root);
        $center->setPreference(7, 'marketing', false, false, false);
        $center->setPreference(7, 'marketing', true, true, false);
        self::assertTrue($center->getUserPreferences(7)['marketing']->isEmailEnabled());
        self::assertSame(1, (int) $this->db->selectOne('SELECT COUNT(*) AS n FROM notification_preferences')['n']);
        $notification = $center->createInAppNotification(7, 'Başlık', 'Türkçe bildirim');
        self::assertSame(1, $center->getUnreadCount(7));
        self::assertTrue($center->markAsRead((int) $notification->getId(), 7));
        self::assertSame(0, $center->getUnreadCount(7));
    }

    public function testDuplicateLegacyScopesBlockMigrationWithoutDroppingReservations(): void
    {
        $server = $this->server();
        $this->reservations->reserve($server);
        $this->reservations->reserve($server);
        $this->db->statement('UPDATE capacity_reservations SET service_id = 77');
        try { (new CapacityScopeSchema($this->db))->upgrade(); self::fail('Conflicts require reconciliation.'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('Duplicate active capacity scope', $error->getMessage()); }
        self::assertCount(2, $this->reservations->listReservationsForServer($server));
        self::assertSame(2, $this->servers->findServerById($server)->getCapacity()->getUsedAccounts());
    }

    public function testCommitBindsScopeAndLaterRetryDoesNotAllocateAgain(): void
    {
        $server = $this->server();
        $res = $this->reservations->reserve($server);
        $this->reservations->commit($res->getToken(), 77);
        self::assertSame($res->getToken(), $this->reservations->reserve($server, serviceId: 77)->getToken());
        self::assertSame(1, $this->servers->findServerById($server)->getCapacity()->getUsedAccounts());
    }
}
