<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Support;

use Coleza\Domain\Support\Departments\Department;
use Coleza\Domain\Support\Departments\DepartmentService;
use Coleza\Domain\Support\Sla\SlaPolicy;
use Coleza\Domain\Support\Sla\SlaService;
use Coleza\Domain\Support\Sla\TicketSla;
use Coleza\Domain\Support\Tickets\TicketPriority;
use Coleza\Domain\Support\Tickets\TicketService;
use Coleza\Domain\Support\Tickets\TicketStatus;
use Coleza\Foundation\Database\Connection;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class SlaSchedulerTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private DepartmentService $departmentService;
    private TicketService $ticketService;
    private SlaService $slaService;
    private Department $deptTech;
    private Department $deptVip;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($this->pdo, 'sqlite');

        $this->departmentService = new DepartmentService($this->db);
        $this->departmentService->ensureTables();

        $this->ticketService = new TicketService($this->db, $this->departmentService);
        $this->ticketService->ensureTables();

        $this->slaService = new SlaService($this->db, $this->ticketService);
        $this->slaService->ensureTables();

        $this->deptTech = $this->departmentService->createDepartment(['name' => 'Tech Support']);
        $this->deptVip = $this->departmentService->createDepartment(['name' => 'VIP Infrastructure']);
    }

    public function testSlaPolicyCreationAndTargetResolution(): void
    {
        // Custom VIP policy
        $vipPolicy = $this->slaService->createPolicy([
            'name' => 'VIP Dedicated 15m Response SLA',
            'department_id' => $this->deptVip->getId(),
            'critical_first_response_minutes' => 15,
            'critical_resolution_minutes' => 60,
            'high_first_response_minutes' => 30,
            'high_resolution_minutes' => 120,
            'is_default' => false,
        ]);

        $this->assertSame('VIP Dedicated 15m Response SLA', $vipPolicy->getName());
        $this->assertSame($this->deptVip->getId(), $vipPolicy->getDepartmentId());
        $this->assertSame(15, $vipPolicy->getCriticalFirstResponseMinutes());
        $this->assertSame(60, $vipPolicy->getCriticalResolutionMinutes());

        // Target lookup
        $targets = $vipPolicy->getTargetsForPriority(TicketPriority::CRITICAL);
        $this->assertSame(15, $targets['first_response_minutes']);
        $this->assertSame(60, $targets['resolution_minutes']);

        // Resolve by department
        $resolved = $this->slaService->resolvePolicyForDepartment($this->deptVip->getId());
        $this->assertSame($vipPolicy->getId(), $resolved->getId());

        // Non-VIP department falls back to standard
        $defaultPolicy = $this->slaService->resolvePolicyForDepartment($this->deptTech->getId());
        $this->assertTrue($defaultPolicy->isDefault());
        $this->assertSame(60, $defaultPolicy->getCriticalFirstResponseMinutes());
    }

    public function testTicketSlaInitializationAndTimers(): void
    {
        $startTime = new DateTimeImmutable('2026-10-09 12:00:00');

        $ticket = $this->ticketService->createTicket([
            'user_id' => 1,
            'department_id' => $this->deptTech->getId(),
            'subject' => 'Critical cluster outage',
            'message' => 'Cluster is down',
            'priority' => TicketPriority::CRITICAL,
        ]);

        $sla = $this->slaService->initializeTicketSla($ticket->getId(), startTime: $startTime);

        $this->assertGreaterThan(0, $sla->getId());
        $this->assertSame($ticket->getId(), $sla->getTicketId());
        $this->assertSame(TicketSla::STATUS_ACTIVE, $sla->getStatus());
        $this->assertFalse($sla->isFirstResponseBreached());
        $this->assertFalse($sla->isResolutionBreached());

        // Critical standard: 60 mins FR, 240 mins resolution
        $expectedFr = new DateTimeImmutable('2026-10-09 13:00:00');
        $expectedRes = new DateTimeImmutable('2026-10-09 16:00:00');

        $this->assertSame($expectedFr->format('Y-m-d H:i:s'), $sla->getFirstResponseDueAt()->format('Y-m-d H:i:s'));
        $this->assertSame($expectedRes->format('Y-m-d H:i:s'), $sla->getResolutionDueAt()->format('Y-m-d H:i:s'));
    }

    public function testFirstResponseWithinTargetAndAfterTarget(): void
    {
        $startTime = new DateTimeImmutable('2026-10-09 10:00:00');

        // Ticket 1: Responded in 20 minutes (Target: 60m) -> MET
        $t1 = $this->ticketService->createTicket([
            'user_id' => 1,
            'department_id' => $this->deptTech->getId(),
            'subject' => 'Issue 1',
            'message' => 'Detail 1',
            'priority' => TicketPriority::CRITICAL,
        ]);
        $this->slaService->initializeTicketSla($t1->getId(), startTime: $startTime);

        $sla1 = $this->slaService->recordFirstResponse(
            ticketId: $t1->getId(),
            responseAt: new DateTimeImmutable('2026-10-09 10:20:00')
        );

        $this->assertNotNull($sla1);
        $this->assertTrue($sla1->isFirstResponseMet());
        $this->assertFalse($sla1->isFirstResponseBreached());

        // Ticket 2: Responded in 75 minutes (Target: 60m) -> BREACHED
        $t2 = $this->ticketService->createTicket([
            'user_id' => 1,
            'department_id' => $this->deptTech->getId(),
            'subject' => 'Issue 2',
            'message' => 'Detail 2',
            'priority' => TicketPriority::CRITICAL,
        ]);
        $this->slaService->initializeTicketSla($t2->getId(), startTime: $startTime);

        $sla2 = $this->slaService->recordFirstResponse(
            ticketId: $t2->getId(),
            responseAt: new DateTimeImmutable('2026-10-09 11:15:00')
        );

        $this->assertNotNull($sla2);
        $this->assertFalse($sla2->isFirstResponseMet());
        $this->assertTrue($sla2->isFirstResponseBreached());
    }

    public function testResolutionRecordingAndStatus(): void
    {
        $startTime = new DateTimeImmutable('2026-10-09 08:00:00');

        $t = $this->ticketService->createTicket([
            'user_id' => 1,
            'department_id' => $this->deptTech->getId(),
            'subject' => 'High issue',
            'message' => 'Detail',
            'priority' => TicketPriority::HIGH, // 240m FR, 720m (12h) Resolution (due at 20:00:00)
        ]);
        $this->slaService->initializeTicketSla($t->getId(), startTime: $startTime);

        // First response on time (09:00:00)
        $this->slaService->recordFirstResponse($t->getId(), new DateTimeImmutable('2026-10-09 09:00:00'));

        // Resolved on time at 15:00:00
        $sla = $this->slaService->recordResolution($t->getId(), new DateTimeImmutable('2026-10-09 15:00:00'));

        $this->assertNotNull($sla);
        $this->assertSame(TicketSla::STATUS_MET, $sla->getStatus());
        $this->assertTrue($sla->isResolutionMet());
        $this->assertFalse($sla->isAnyBreached());
    }

    public function testPauseAndResumeExtendsDueDates(): void
    {
        $startTime = new DateTimeImmutable('2026-10-09 10:00:00');

        $ticket = $this->ticketService->createTicket([
            'user_id' => 1,
            'department_id' => $this->deptTech->getId(),
            'subject' => 'Investigate with hardware vendor',
            'message' => 'Waiting for vendor replacement',
            'priority' => TicketPriority::CRITICAL, // FR due 11:00:00, Res due 14:00:00
        ]);

        $sla = $this->slaService->initializeTicketSla($ticket->getId(), startTime: $startTime);

        // Put on hold at 10:30:00
        $pausedSla = $this->slaService->pauseSla($ticket->getId(), new DateTimeImmutable('2026-10-09 10:30:00'));
        $this->assertTrue($pausedSla->isPaused());
        $this->assertSame(TicketSla::STATUS_PAUSED, $pausedSla->getStatus());

        // Resume at 12:30:00 (2 hours paused = 7200 seconds)
        $resumedSla = $this->slaService->resumeSla($ticket->getId(), new DateTimeImmutable('2026-10-09 12:30:00'));
        $this->assertFalse($resumedSla->isPaused());
        $this->assertSame(TicketSla::STATUS_ACTIVE, $resumedSla->getStatus());
        $this->assertSame(7200, $resumedSla->getTotalPausedSeconds());

        // Due dates should have shifted forward by 2 hours:
        // FR was 11:00 -> becomes 13:00
        // Resolution was 14:00 -> becomes 16:00
        $this->assertSame('2026-10-09 13:00:00', $resumedSla->getFirstResponseDueAt()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-09 16:00:00', $resumedSla->getResolutionDueAt()->format('Y-m-d H:i:s'));
    }

    public function testEvaluateBreachesAndAutoEscalation(): void
    {
        $startTime = new DateTimeImmutable('2026-10-09 08:00:00');

        // Ticket 1: Medium priority (FR: 12h = 20:00:00, Res: 24h = next day 08:00:00)
        $t1 = $this->ticketService->createTicket([
            'user_id' => 1,
            'department_id' => $this->deptTech->getId(),
            'subject' => 'Slow inquiry',
            'message' => 'Details',
            'priority' => TicketPriority::MEDIUM,
        ]);
        $this->slaService->initializeTicketSla($t1->getId(), startTime: $startTime);

        // Check at 19:00:00 -> Not breached yet
        $resultEarly = $this->slaService->evaluateBreaches(new DateTimeImmutable('2026-10-09 19:00:00'));
        $this->assertFalse($resultEarly->hasBreaches());

        // Check at 21:00:00 with autoEscalatePriority = true -> First response breached!
        $resultBreached = $this->slaService->evaluateBreaches(
            currentTime: new DateTimeImmutable('2026-10-09 21:00:00'),
            autoEscalatePriority: true
        );

        $this->assertTrue($resultBreached->hasBreaches());
        $this->assertContains($t1->getId(), $resultBreached->firstResponseBreaches);
        $this->assertContains($t1->getId(), $resultBreached->escalatedTickets);

        // Verify ticket priority was auto-escalated from MEDIUM to HIGH!
        $escalatedTicket = $this->ticketService->requireTicket($t1->getId());
        $this->assertSame(TicketPriority::HIGH, $escalatedTicket->getPriority());

        // Verify SLA row marked as breached
        $sla = $this->slaService->getTicketSla($t1->getId());
        $this->assertTrue($sla->isFirstResponseBreached());
        $this->assertSame(TicketSla::STATUS_BREACHED, $sla->getStatus());
    }
}
