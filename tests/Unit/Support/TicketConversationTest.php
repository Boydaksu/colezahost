<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Support;

use Coleza\Domain\Support\Departments\Department;
use Coleza\Domain\Support\Departments\DepartmentService;
use Coleza\Domain\Support\Tickets\TicketPriority;
use Coleza\Domain\Support\Tickets\TicketService;
use Coleza\Domain\Support\Tickets\TicketStatus;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class TicketConversationTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private DepartmentService $departmentService;
    private TicketService $ticketService;
    private Department $deptSales;
    private Department $deptTech;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($this->pdo, 'sqlite');

        $this->departmentService = new DepartmentService($this->db);
        $this->departmentService->ensureTables();

        $this->ticketService = new TicketService($this->db, $this->departmentService);
        $this->ticketService->ensureTables();

        $this->deptSales = $this->departmentService->createDepartment([
            'name' => 'Sales',
            'email' => 'sales@colezahost.com',
            'is_public' => true,
        ]);

        $this->deptTech = $this->departmentService->createDepartment([
            'name' => 'Technical Support',
            'email' => 'tech@colezahost.com',
            'is_public' => true,
        ]);

        // Assign staff agent 99 to Tech department
        $this->departmentService->assignAgent($this->deptTech->getId(), userId: 99, role: 'agent');
    }

    public function testTicketCreationWithInitialMessage(): void
    {
        $ticket = $this->ticketService->createTicket([
            'user_id' => 10,
            'department_id' => $this->deptTech->getId(),
            'subject' => 'Cannot connect to cPanel server',
            'message' => 'Hello, I am receiving connection timeout error when loading port 2083.',
            'priority' => TicketPriority::HIGH,
            'service_id' => 501,
        ]);

        $this->assertGreaterThan(0, $ticket->getId());
        $this->assertStringStartsWith('TIC-', $ticket->getTicketNumber());
        $this->assertSame(10, $ticket->getUserId());
        $this->assertSame($this->deptTech->getId(), $ticket->getDepartmentId());
        $this->assertSame('Cannot connect to cPanel server', $ticket->getSubject());
        $this->assertSame(TicketStatus::OPEN, $ticket->getStatus());
        $this->assertSame(TicketPriority::HIGH, $ticket->getPriority());
        $this->assertSame(501, $ticket->getServiceId());
        $this->assertTrue($ticket->isOpen());
        $this->assertFalse($ticket->isClosed());
        $this->assertFalse($ticket->isAssigned());
        $this->assertFalse($ticket->isLastReplyByStaff());

        // Check initial conversation message
        $conversation = $this->ticketService->getConversation($ticket->getId());
        $this->assertCount(1, $conversation);
        $this->assertSame('Hello, I am receiving connection timeout error when loading port 2083.', $conversation[0]->getMessage());
        $this->assertFalse($conversation[0]->isStaff());
        $this->assertFalse($conversation[0]->isInternalNote());
        $this->assertSame(10, $conversation[0]->getUserId());

        // Lookup by ticket number
        $found = $this->ticketService->getTicketByNumber($ticket->getTicketNumber());
        $this->assertNotNull($found);
        $this->assertSame($ticket->getId(), $found->getId());
    }

    public function testTicketCreationValidationFailures(): void
    {
        // Missing user ID
        try {
            $this->ticketService->createTicket([
                'user_id' => 0,
                'department_id' => $this->deptSales->getId(),
                'subject' => 'Question',
                'message' => 'Details',
            ]);
            $this->fail('Expected ValidationException for missing user');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('user_id', $e->getErrors());
        }

        // Inactive department
        $inactiveDept = $this->departmentService->createDepartment([
            'name' => 'Legacy Dept',
            'is_active' => false,
        ]);
        try {
            $this->ticketService->createTicket([
                'user_id' => 10,
                'department_id' => $inactiveDept->getId(),
                'subject' => 'Question',
                'message' => 'Details',
            ]);
            $this->fail('Expected ValidationException for inactive department');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('department_id', $e->getErrors());
        }

        // Empty subject
        try {
            $this->ticketService->createTicket([
                'user_id' => 10,
                'department_id' => $this->deptSales->getId(),
                'subject' => '   ',
                'message' => 'Details',
            ]);
            $this->fail('Expected ValidationException for empty subject');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('subject', $e->getErrors());
        }

        // Empty message
        try {
            $this->ticketService->createTicket([
                'user_id' => 10,
                'department_id' => $this->deptSales->getId(),
                'subject' => 'Valid Subject',
                'message' => '',
            ]);
            $this->fail('Expected ValidationException for empty message');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('message', $e->getErrors());
        }
    }

    public function testStaffReplyAndCustomerReplyFlow(): void
    {
        $ticket = $this->ticketService->createTicket([
            'user_id' => 10,
            'department_id' => $this->deptTech->getId(),
            'subject' => 'DNS Propagation Issue',
            'message' => 'My nameservers have not updated yet.',
        ]);

        $this->assertSame(TicketStatus::OPEN, $ticket->getStatus());

        // 1. Staff responds
        $staffReply = $this->ticketService->addReply(
            ticketId: $ticket->getId(),
            userId: 99,
            message: 'We checked and your NS records are propagating now. Please allow 1-2 hours.',
            isStaff: true
        );

        $this->assertTrue($staffReply->isStaff());
        $this->assertFalse($staffReply->isInternalNote());

        $reloaded = $this->ticketService->requireTicket($ticket->getId());
        $this->assertSame(TicketStatus::ANSWERED, $reloaded->getStatus());
        $this->assertTrue($reloaded->isLastReplyByStaff());
        $this->assertSame(99, $reloaded->getLastReplyUserId());

        // 2. Customer replies back
        $custReply = $this->ticketService->addReply(
            ticketId: $ticket->getId(),
            userId: 10,
            message: 'Thank you! It is now working properly.',
            isStaff: false
        );

        $this->assertFalse($custReply->isStaff());
        $this->assertFalse($custReply->isInternalNote());

        $reloaded2 = $this->ticketService->requireTicket($ticket->getId());
        $this->assertSame(TicketStatus::CUSTOMER_REPLY, $reloaded2->getStatus());
        $this->assertFalse($reloaded2->isLastReplyByStaff());
        $this->assertSame(10, $reloaded2->getLastReplyUserId());

        $conversation = $this->ticketService->getConversation($ticket->getId());
        $this->assertCount(3, $conversation);
    }

    public function testInternalNotesVisibilityAndSeparation(): void
    {
        $ticket = $this->ticketService->createTicket([
            'user_id' => 10,
            'department_id' => $this->deptTech->getId(),
            'subject' => 'Suspected DDoS Attack',
            'message' => 'Our website is very slow right now.',
        ]);

        // Staff adds internal private note
        $note = $this->ticketService->addInternalNote(
            ticketId: $ticket->getId(),
            staffUserId: 99,
            message: 'CONFIDENTIAL: Server ingress is 8Gbps. Mitigation filter activated.'
        );

        $this->assertTrue($note->isStaff());
        $this->assertTrue($note->isInternalNote());

        // Customer view: Internal notes MUST be hidden!
        $customerView = $this->ticketService->getConversation($ticket->getId(), includeInternalNotes: false);
        $this->assertCount(1, $customerView);
        $this->assertSame('Our website is very slow right now.', $customerView[0]->getMessage());

        // Staff view: Internal notes ARE included
        $staffView = $this->ticketService->getConversation($ticket->getId(), includeInternalNotes: true);
        $this->assertCount(2, $staffView);
        $this->assertSame('CONFIDENTIAL: Server ingress is 8Gbps. Mitigation filter activated.', $staffView[1]->getMessage());

        // Verify internal note did NOT alter ticket customer status or mark as answered
        $reloaded = $this->ticketService->requireTicket($ticket->getId());
        $this->assertSame(TicketStatus::OPEN, $reloaded->getStatus());
        $this->assertFalse($reloaded->isLastReplyByStaff());
    }

    public function testCustomerReplyReopensResolvedOrClosedTicket(): void
    {
        $ticket = $this->ticketService->createTicket([
            'user_id' => 10,
            'department_id' => $this->deptTech->getId(),
            'subject' => 'SSL Certificate Issue',
            'message' => 'SSL certificate is expired.',
        ]);

        // Staff resolves it
        $this->ticketService->updateStatus($ticket->getId(), TicketStatus::RESOLVED);
        $reloaded = $this->ticketService->requireTicket($ticket->getId());
        $this->assertSame(TicketStatus::RESOLVED, $reloaded->getStatus());

        // Customer replies on resolved ticket
        $this->ticketService->addReply(
            ticketId: $ticket->getId(),
            userId: 10,
            message: 'Actually, it is still throwing an untrusted cert warning.',
            isStaff: false
        );

        $reopened = $this->ticketService->requireTicket($ticket->getId());
        $this->assertSame(TicketStatus::CUSTOMER_REPLY, $reopened->getStatus());
        $this->assertTrue($reopened->isOpen());

        // Staff closes it
        $this->ticketService->updateStatus($ticket->getId(), TicketStatus::CLOSED);
        $closed = $this->ticketService->requireTicket($ticket->getId());
        $this->assertTrue($closed->isClosed());
        $this->assertNotNull($closed->getClosedAt());

        // Customer replies to closed ticket -> automatically re-opens and clears closed_at
        $this->ticketService->addReply(
            ticketId: $ticket->getId(),
            userId: 10,
            message: 'I still have a question about this.',
            isStaff: false
        );

        $reopenedAgain = $this->ticketService->requireTicket($ticket->getId());
        $this->assertSame(TicketStatus::CUSTOMER_REPLY, $reopenedAgain->getStatus());
        $this->assertNull($reopenedAgain->getClosedAt());
    }

    public function testTicketAssignmentAndDepartmentTransfer(): void
    {
        $ticket = $this->ticketService->createTicket([
            'user_id' => 10,
            'department_id' => $this->deptTech->getId(),
            'subject' => 'Billing inquiry routed to tech by accident',
            'message' => 'Please refund invoice #12.',
        ]);

        // Assign to tech agent 99
        $assigned = $this->ticketService->assignTicket($ticket->getId(), 99);
        $this->assertTrue($assigned->isAssigned());
        $this->assertSame(99, $assigned->getAssignedTo());

        // Assign to agent outside department -> fails
        try {
            $this->ticketService->assignTicket($ticket->getId(), agentUserId: 888);
            $this->fail('Expected ValidationException for non-member agent assignment');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('assigned_to', $e->getErrors());
        }

        // Assign sales agent 77 to sales department
        $this->departmentService->assignAgent($this->deptSales->getId(), userId: 77);

        // Transfer ticket to Sales department and reassign to agent 77
        $transferred = $this->ticketService->transferDepartment(
            ticketId: $ticket->getId(),
            newDepartmentId: $this->deptSales->getId(),
            newAgentUserId: 77
        );

        $this->assertSame($this->deptSales->getId(), $transferred->getDepartmentId());
        $this->assertSame(77, $transferred->getAssignedTo());
    }

    public function testTicketListFiltering(): void
    {
        $t1 = $this->ticketService->createTicket([
            'user_id' => 10,
            'department_id' => $this->deptTech->getId(),
            'subject' => 'Tech question 1',
            'message' => 'Body 1',
            'status' => TicketStatus::OPEN,
            'priority' => TicketPriority::LOW,
        ]);

        $t2 = $this->ticketService->createTicket([
            'user_id' => 20,
            'department_id' => $this->deptTech->getId(),
            'subject' => 'Tech question 2',
            'message' => 'Body 2',
            'status' => TicketStatus::IN_PROGRESS,
            'priority' => TicketPriority::CRITICAL,
        ]);

        $t3 = $this->ticketService->createTicket([
            'user_id' => 10,
            'department_id' => $this->deptSales->getId(),
            'subject' => 'Sales question 1',
            'message' => 'Body 3',
            'status' => TicketStatus::CLOSED,
            'priority' => TicketPriority::MEDIUM,
        ]);

        // Filter by user 10
        $byUser10 = $this->ticketService->listTickets(['user_id' => 10]);
        $this->assertCount(2, $byUser10);

        // Filter by department Sales
        $bySales = $this->ticketService->listTickets(['department_id' => $this->deptSales->getId()]);
        $this->assertCount(1, $bySales);
        $this->assertSame($t3->getId(), $bySales[0]->getId());

        // Filter by priority Critical
        $criticalTickets = $this->ticketService->listTickets(['priority' => TicketPriority::CRITICAL]);
        $this->assertCount(1, $criticalTickets);
        $this->assertSame($t2->getId(), $criticalTickets[0]->getId());

        // Filter by multiple statuses
        $activeStatuses = $this->ticketService->listTickets([
            'statuses' => [TicketStatus::OPEN, TicketStatus::IN_PROGRESS],
        ]);
        $this->assertCount(2, $activeStatuses);
    }
}
