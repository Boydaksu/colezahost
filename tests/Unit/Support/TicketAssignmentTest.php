<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Support;

use Coleza\Domain\Support\Assignments\TicketAssignmentService;
use Coleza\Domain\Support\Departments\Department;
use Coleza\Domain\Support\Departments\DepartmentService;
use Coleza\Domain\Support\Tickets\TicketPriority;
use Coleza\Domain\Support\Tickets\TicketService;
use Coleza\Domain\Support\Tickets\TicketStatus;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class TicketAssignmentTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private DepartmentService $departmentService;
    private TicketService $ticketService;
    private TicketAssignmentService $assignmentService;
    private Department $deptSupport;
    private Department $deptBilling;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($this->pdo, 'sqlite');

        $this->departmentService = new DepartmentService($this->db);
        $this->departmentService->ensureTables();

        $this->ticketService = new TicketService($this->db, $this->departmentService);
        $this->ticketService->ensureTables();

        $this->assignmentService = new TicketAssignmentService($this->db, $this->departmentService, $this->ticketService);
        $this->assignmentService->ensureTables();

        $this->deptSupport = $this->departmentService->createDepartment([
            'name' => 'Technical Support',
            'is_public' => true,
        ]);

        $this->deptBilling = $this->departmentService->createDepartment([
            'name' => 'Billing & Finance',
            'is_public' => true,
        ]);
    }

    public function testManualAssignmentAndUnassign(): void
    {
        // Add agent 10 to Support
        $this->departmentService->assignAgent($this->deptSupport->getId(), userId: 10, role: 'agent');

        $ticket = $this->ticketService->createTicket([
            'user_id' => 1,
            'department_id' => $this->deptSupport->getId(),
            'subject' => 'Server unreachable',
            'message' => 'Cannot ping server IP',
        ]);

        $this->assertNull($ticket->getAssignedTo());

        // Assign manually
        $log = $this->assignmentService->assignManual(
            ticketId: $ticket->getId(),
            agentUserId: 10,
            actorUserId: 999, // supervisor
            reason: 'High priority manual dispatch'
        );

        $this->assertSame(TicketAssignmentService::STRATEGY_MANUAL, $log->getStrategy());
        $this->assertSame(10, $log->getNewAssignedTo());
        $this->assertNull($log->getPreviousAssignedTo());
        $this->assertSame(999, $log->getAssignedByUserId());
        $this->assertSame('High priority manual dispatch', $log->getReason());

        $updatedTicket = $this->ticketService->requireTicket($ticket->getId());
        $this->assertSame(10, $updatedTicket->getAssignedTo());

        // Unassign
        $unassignLog = $this->assignmentService->unassign($ticket->getId(), actorUserId: 999, reason: 'Shift ended');
        $this->assertSame(TicketAssignmentService::STRATEGY_UNASSIGN, $unassignLog->getStrategy());
        $this->assertSame(10, $unassignLog->getPreviousAssignedTo());
        $this->assertNull($unassignLog->getNewAssignedTo());

        $unassignedTicket = $this->ticketService->requireTicket($ticket->getId());
        $this->assertNull($unassignedTicket->getAssignedTo());

        // Audit history has 2 records
        $history = $this->assignmentService->getAssignmentHistory($ticket->getId());
        $this->assertCount(2, $history);
    }

    public function testManualAssignmentRejectsAgentOutsideDepartment(): void
    {
        // Add agent 20 to Billing (NOT Support)
        $this->departmentService->assignAgent($this->deptBilling->getId(), userId: 20);

        $ticket = $this->ticketService->createTicket([
            'user_id' => 1,
            'department_id' => $this->deptSupport->getId(),
            'subject' => 'DNS inquiry',
            'message' => 'Help with DNS',
        ]);

        $this->expectException(ValidationException::class);
        $this->assignmentService->assignManual(
            ticketId: $ticket->getId(),
            agentUserId: 20
        );
    }

    public function testRoundRobinRotationAcrossAgents(): void
    {
        // Register 3 agents in Support
        $this->departmentService->assignAgent($this->deptSupport->getId(), userId: 101, canAssign: true);
        $this->departmentService->assignAgent($this->deptSupport->getId(), userId: 102, canAssign: true);
        $this->departmentService->assignAgent($this->deptSupport->getId(), userId: 103, canAssign: true);

        // Ticket 1 -> Agent 101
        $t1 = $this->ticketService->createTicket([
            'user_id' => 1,
            'department_id' => $this->deptSupport->getId(),
            'subject' => 'Issue 1',
            'message' => 'Message 1',
        ]);
        $log1 = $this->assignmentService->assignRoundRobin($t1->getId());
        $this->assertNotNull($log1);
        $this->assertSame(101, $log1->getNewAssignedTo());
        $this->assertSame(101, $this->ticketService->requireTicket($t1->getId())->getAssignedTo());

        // Ticket 2 -> Agent 102
        $t2 = $this->ticketService->createTicket([
            'user_id' => 1,
            'department_id' => $this->deptSupport->getId(),
            'subject' => 'Issue 2',
            'message' => 'Message 2',
        ]);
        $log2 = $this->assignmentService->assignRoundRobin($t2->getId());
        $this->assertNotNull($log2);
        $this->assertSame(102, $log2->getNewAssignedTo());

        // Ticket 3 -> Agent 103
        $t3 = $this->ticketService->createTicket([
            'user_id' => 1,
            'department_id' => $this->deptSupport->getId(),
            'subject' => 'Issue 3',
            'message' => 'Message 3',
        ]);
        $log3 = $this->assignmentService->assignRoundRobin($t3->getId());
        $this->assertNotNull($log3);
        $this->assertSame(103, $log3->getNewAssignedTo());

        // Ticket 4 -> Rotates back to Agent 101!
        $t4 = $this->ticketService->createTicket([
            'user_id' => 1,
            'department_id' => $this->deptSupport->getId(),
            'subject' => 'Issue 4',
            'message' => 'Message 4',
        ]);
        $log4 = $this->assignmentService->assignRoundRobin($t4->getId());
        $this->assertNotNull($log4);
        $this->assertSame(101, $log4->getNewAssignedTo());
    }

    public function testRoundRobinIgnoresNonAssignableOrInactiveAgents(): void
    {
        // Agent 201: Active, can_assign = true
        $this->departmentService->assignAgent($this->deptSupport->getId(), userId: 201, canAssign: true);
        // Agent 202: Active, can_assign = false (manager/viewer)
        $this->departmentService->assignAgent($this->deptSupport->getId(), userId: 202, canAssign: false);
        // Agent 203: Active, can_assign = true
        $this->departmentService->assignAgent($this->deptSupport->getId(), userId: 203, canAssign: true);

        $t1 = $this->ticketService->createTicket([
            'user_id' => 1,
            'department_id' => $this->deptSupport->getId(),
            'subject' => 'Issue A',
            'message' => 'Body A',
        ]);
        $log1 = $this->assignmentService->assignRoundRobin($t1->getId());
        $this->assertSame(201, $log1->getNewAssignedTo());

        $t2 = $this->ticketService->createTicket([
            'user_id' => 1,
            'department_id' => $this->deptSupport->getId(),
            'subject' => 'Issue B',
            'message' => 'Body B',
        ]);
        // Agent 202 should be skipped! Must assign to 203.
        $log2 = $this->assignmentService->assignRoundRobin($t2->getId());
        $this->assertSame(203, $log2->getNewAssignedTo());
    }

    public function testRoundRobinReturnsNullWhenDepartmentHasNoAssignableAgents(): void
    {
        $ticket = $this->ticketService->createTicket([
            'user_id' => 1,
            'department_id' => $this->deptBilling->getId(),
            'subject' => 'Invoice payment issue',
            'message' => 'Cannot pay',
        ]);

        // No agents assigned to Billing department
        $log = $this->assignmentService->assignRoundRobin($ticket->getId());
        $this->assertNull($log);

        $unassigned = $this->ticketService->requireTicket($ticket->getId());
        $this->assertNull($unassigned->getAssignedTo());
    }

    public function testLeastLoadedAssignmentStrategy(): void
    {
        $this->departmentService->assignAgent($this->deptSupport->getId(), userId: 301, canAssign: true);
        $this->departmentService->assignAgent($this->deptSupport->getId(), userId: 302, canAssign: true);
        $this->departmentService->assignAgent($this->deptSupport->getId(), userId: 303, canAssign: true);

        // Pre-load Agent 301 with 2 open tickets
        $this->ticketService->createTicket([
            'user_id' => 1,
            'department_id' => $this->deptSupport->getId(),
            'subject' => 'T1 for 301',
            'message' => 'M1',
            'assigned_to' => 301,
            'status' => TicketStatus::OPEN,
        ]);
        $this->ticketService->createTicket([
            'user_id' => 1,
            'department_id' => $this->deptSupport->getId(),
            'subject' => 'T2 for 301',
            'message' => 'M2',
            'assigned_to' => 301,
            'status' => TicketStatus::IN_PROGRESS,
        ]);

        // Pre-load Agent 302 with 1 open ticket + 1 closed ticket (closed should not count!)
        $this->ticketService->createTicket([
            'user_id' => 1,
            'department_id' => $this->deptSupport->getId(),
            'subject' => 'T1 for 302',
            'message' => 'M1',
            'assigned_to' => 302,
            'status' => TicketStatus::OPEN,
        ]);
        $this->ticketService->createTicket([
            'user_id' => 1,
            'department_id' => $this->deptSupport->getId(),
            'subject' => 'Closed ticket for 302',
            'message' => 'M closed',
            'assigned_to' => 302,
            'status' => TicketStatus::CLOSED,
        ]);

        // Agent 303 has 0 open tickets!

        // Now create new ticket and dispatch via least-loaded
        $newTicket = $this->ticketService->createTicket([
            'user_id' => 1,
            'department_id' => $this->deptSupport->getId(),
            'subject' => 'Least loaded test',
            'message' => 'Dispatch to least busy agent',
        ]);

        $log = $this->assignmentService->assignLeastLoaded($newTicket->getId());
        $this->assertNotNull($log);
        $this->assertSame(303, $log->getNewAssignedTo());
        $this->assertSame(TicketAssignmentService::STRATEGY_LEAST_LOADED, $log->getStrategy());

        $reloaded = $this->ticketService->requireTicket($newTicket->getId());
        $this->assertSame(303, $reloaded->getAssignedTo());
    }
}
