<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Support;

use Coleza\Domain\Support\Announcements\Announcement;
use Coleza\Domain\Support\Announcements\AnnouncementService;
use Coleza\Domain\Support\CannedResponses\CannedResponse;
use Coleza\Domain\Support\CannedResponses\CannedResponseService;
use Coleza\Domain\Support\Departments\Department;
use Coleza\Domain\Support\Departments\DepartmentService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class CannedResponsesAndAnnouncementsTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private DepartmentService $departmentService;
    private CannedResponseService $cannedService;
    private AnnouncementService $announcementService;
    private Department $deptTech;
    private Department $deptBilling;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($this->pdo, 'sqlite');

        $this->departmentService = new DepartmentService($this->db);
        $this->departmentService->ensureTables();

        $this->cannedService = new CannedResponseService($this->db, $this->departmentService);
        $this->cannedService->ensureTables();

        $this->announcementService = new AnnouncementService($this->db);
        $this->announcementService->ensureTables();

        $this->deptTech = $this->departmentService->createDepartment(['name' => 'Technical']);
        $this->deptBilling = $this->departmentService->createDepartment(['name' => 'Billing']);
    }

    public function testCannedResponseCreationAndRendering(): void
    {
        $template = "Hello {{ client_name }},\n\nThank you for reaching out regarding ticket #{{ ticket_number }}. Your DNS has been refreshed.\n\nBest regards,\nColeza Support";

        $response = $this->cannedService->create([
            'title' => 'DNS Propagation Refresh',
            'shortcut' => 'dns-refresh', // should auto-prefix with /
            'content' => $template,
            'department_id' => $this->deptTech->getId(),
        ]);

        $this->assertGreaterThan(0, $response->getId());
        $this->assertSame('DNS Propagation Refresh', $response->getTitle());
        $this->assertSame('/dns-refresh', $response->getShortcut());
        $this->assertSame($this->deptTech->getId(), $response->getDepartmentId());
        $this->assertTrue($response->isActive());

        // Test rendering with placeholder variables
        $rendered = $response->render([
            'client_name' => 'Alican',
            'ticket_number' => 'TIC-20261009-ABC12',
        ]);

        $this->assertStringContainsString('Hello Alican,', $rendered);
        $this->assertStringContainsString('ticket #TIC-20261009-ABC12', $rendered);
        $this->assertStringNotContainsString('{{ client_name }}', $rendered);

        // Serialization
        $arr = $response->toArray();
        $reconstructed = CannedResponse::fromArray($arr);
        $this->assertSame($response->getId(), $reconstructed->getId());
        $this->assertSame($response->getTitle(), $reconstructed->getTitle());
    }

    public function testCannedResponseShortcutLookupAndDepartmentPrecedence(): void
    {
        // 1. Global fallback response with shortcut /greeting
        $this->cannedService->create([
            'title' => 'Global Greeting',
            'shortcut' => '/greeting',
            'content' => 'Hello {{ client_name }}, global greeting.',
            'department_id' => null,
        ]);

        // 2. Billing specific greeting with same shortcut /greeting
        $this->cannedService->create([
            'title' => 'Billing Greeting',
            'shortcut' => '/greeting',
            'content' => 'Dear {{ client_name }}, billing greeting.',
            'department_id' => $this->deptBilling->getId(),
        ]);

        // Lookup in Tech department (no tech specific, so should get global)
        $techLookup = $this->cannedService->findByShortcut('/greeting', $this->deptTech->getId());
        $this->assertNotNull($techLookup);
        $this->assertSame('Global Greeting', $techLookup->getTitle());

        // Lookup in Billing department (should get billing specific precedence)
        $billingLookup = $this->cannedService->findByShortcut('/greeting', $this->deptBilling->getId());
        $this->assertNotNull($billingLookup);
        $this->assertSame('Billing Greeting', $billingLookup->getTitle());
    }

    public function testCannedResponseUpdateAndDelete(): void
    {
        $response = $this->cannedService->create([
            'title' => 'Refund Policy',
            'shortcut' => '/refund',
            'content' => 'Standard refund policy info',
        ]);

        $updated = $this->cannedService->update($response->getId(), [
            'title' => 'Updated Refund Policy V2',
            'content' => 'New refund terms apply within 14 days.',
            'shortcut' => '/refund-terms',
        ]);

        $this->assertSame('Updated Refund Policy V2', $updated->getTitle());
        $this->assertSame('/refund-terms', $updated->getShortcut());
        $this->assertSame('New refund terms apply within 14 days.', $updated->getContent());

        // Delete
        $deleted = $this->cannedService->delete($response->getId());
        $this->assertTrue($deleted);
        $this->assertNull($this->cannedService->get($response->getId()));
    }

    public function testCannedResponseValidationFailures(): void
    {
        $this->expectException(ValidationException::class);
        $this->cannedService->create([
            'title' => '',
            'content' => 'something',
        ]);
    }

    public function testAnnouncementCreationAndSchedule(): void
    {
        $now = new DateTimeImmutable('2026-10-09 10:00:00');

        $announcement = $this->announcementService->create([
            'title' => 'Scheduled Maintenance: Frankfurt Datacenter Core Switch',
            'content' => 'Network switches in FRA-01 will undergo firmware upgrade from 02:00 to 04:00 UTC.',
            'type' => Announcement::TYPE_MAINTENANCE,
            'is_pinned' => true,
            'is_public' => true,
            'published_at' => new DateTimeImmutable('2026-10-09 08:00:00'),
            'expires_at' => new DateTimeImmutable('2026-10-09 18:00:00'),
        ]);

        $this->assertGreaterThan(0, $announcement->getId());
        $this->assertStringContainsString('scheduled-maintenance-frankfurt', $announcement->getSlug());
        $this->assertSame(Announcement::TYPE_MAINTENANCE, $announcement->getType());
        $this->assertSame('badge-warning', $announcement->getTypeBadgeClass());
        $this->assertTrue($announcement->isPinned());
        $this->assertTrue($announcement->isPublic());

        // At 10:00:00 -> It is live
        $this->assertTrue($announcement->isLive($now));

        // Before publish date (07:00:00) -> Not published
        $this->assertFalse($announcement->isLive(new DateTimeImmutable('2026-10-09 07:00:00')));

        // After expires date (19:00:00) -> Expired
        $this->assertTrue($announcement->isExpired(new DateTimeImmutable('2026-10-09 19:00:00')));
        $this->assertFalse($announcement->isLive(new DateTimeImmutable('2026-10-09 19:00:00')));

        // Serialization
        $arr = $announcement->toArray();
        $reconstructed = Announcement::fromArray($arr);
        $this->assertSame($announcement->getId(), $reconstructed->getId());
        $this->assertSame($announcement->getSlug(), $reconstructed->getSlug());
    }

    public function testLiveAnnouncementsListingAndPinPrecedence(): void
    {
        $now = new DateTimeImmutable('2026-10-09 12:00:00');

        // 1. Regular public announcement (published at 11:00, not pinned)
        $this->announcementService->create([
            'title' => 'Regular News',
            'content' => 'News body',
            'is_pinned' => false,
            'is_public' => true,
            'published_at' => new DateTimeImmutable('2026-10-09 11:00:00'),
        ]);

        // 2. Pinned public announcement (published at 09:00, pinned = true)
        $pinned = $this->announcementService->create([
            'title' => 'Urgent Network Notice',
            'content' => 'Urgent body',
            'is_pinned' => true,
            'is_public' => true,
            'published_at' => new DateTimeImmutable('2026-10-09 09:00:00'),
        ]);

        // 3. Private / logged-in only announcement
        $private = $this->announcementService->create([
            'title' => 'Internal Customer Notice',
            'content' => 'Customer portal only',
            'is_pinned' => false,
            'is_public' => false,
            'published_at' => new DateTimeImmutable('2026-10-09 10:00:00'),
        ]);

        // 4. Expired announcement (should be excluded)
        $this->announcementService->create([
            'title' => 'Old notice',
            'content' => 'Expired',
            'published_at' => new DateTimeImmutable('2026-10-09 06:00:00'),
            'expires_at' => new DateTimeImmutable('2026-10-09 08:00:00'),
        ]);

        // Public feed: only public and live; pinned item MUST come first!
        $publicList = $this->announcementService->listLiveAnnouncements(onlyPublic: true, now: $now);
        $this->assertCount(2, $publicList);
        $this->assertSame($pinned->getId(), $publicList[0]->getId()); // Pinned is first

        // Logged-in feed: includes private live announcements
        $allLiveList = $this->announcementService->listLiveAnnouncements(onlyPublic: false, now: $now);
        $this->assertCount(3, $allLiveList);
    }

    public function testAnnouncementUpdateAndDelete(): void
    {
        $ann = $this->announcementService->create([
            'title' => 'Title One',
            'content' => 'Content One',
        ]);

        $updated = $this->announcementService->update($ann->getId(), [
            'title' => 'Title One Modified',
            'content' => 'Content One Modified',
            'type' => Announcement::TYPE_INCIDENT,
        ]);

        $this->assertSame('Title One Modified', $updated->getTitle());
        $this->assertSame(Announcement::TYPE_INCIDENT, $updated->getType());

        $this->assertTrue($this->announcementService->delete($ann->getId()));
        $this->assertNull($this->announcementService->get($ann->getId()));
    }
}
