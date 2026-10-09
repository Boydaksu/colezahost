<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Support;

use Coleza\Domain\Support\Departments\Department;
use Coleza\Domain\Support\Departments\DepartmentService;
use Coleza\Domain\Support\Permissions\SupportUserContext;
use Coleza\Domain\Support\Permissions\TicketPermissionService;
use Coleza\Domain\Support\Tickets\Ticket;
use Coleza\Domain\Support\Tickets\TicketService;
use Coleza\Domain\Support\Tickets\TicketStatus;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class OrganizationTicketPermissionsTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private DepartmentService $departmentService;
    private TicketService $ticketService;
    private TicketPermissionService $permissionService;
    private Department $deptTech;
    private Department $deptSales;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($this->pdo, 'sqlite');

        $this->departmentService = new DepartmentService($this->db);
        $this->departmentService->ensureTables();

        $this->ticketService = new TicketService($this->db, $this->departmentService);
        $this->ticketService->ensureTables();

        $this->permissionService = new TicketPermissionService();

        $this->deptTech = $this->departmentService->createDepartment(['name' => 'Technical Dept']);
        $this->deptSales = $this->departmentService->createDepartment(['name' => 'Sales Dept']);
    }

    public function testCrossOrganizationIsolationBarrier(): void
    {
        // Ticket created in Org 100
        $ticketOrg100 = $this->ticketService->createTicket([
            'user_id' => 1,
            'organization_id' => 100,
            'department_id' => $this->deptTech->getId(),
            'subject' => 'Org 100 Secret Infrastructure Inquiry',
            'message' => 'Sensitive org 100 info',
        ]);

        // User belongs to rival Org 200 (even as Owner!)
        $userOrg200Owner = SupportUserContext::orgOwner(userId: 99, organizationId: 200);

        // Access MUST be strictly denied
        $this->assertFalse($this->permissionService->canView($ticketOrg100, $userOrg200Owner));
        $this->assertFalse($this->permissionService->canReply($ticketOrg100, $userOrg200Owner));
        $this->assertFalse($this->permissionService->canManage($ticketOrg100, $userOrg200Owner));

        $this->expectException(ValidationException::class);
        $this->permissionService->assertCanView($ticketOrg100, $userOrg200Owner);
    }

    public function testOrganizationOwnerAndAdminCanViewAllOrgTickets(): void
    {
        // Member 5 creates ticket in Org 100
        $ticket = $this->ticketService->createTicket([
            'user_id' => 5,
            'organization_id' => 100,
            'department_id' => $this->deptTech->getId(),
            'subject' => 'Database issue',
            'message' => 'DB latency high',
        ]);

        $owner = SupportUserContext::orgOwner(userId: 1, organizationId: 100);
        $admin = SupportUserContext::orgAdmin(userId: 2, organizationId: 100);

        // Both owner and admin can view, reply to, and manage colleague's ticket
        $this->assertTrue($this->permissionService->canView($ticket, $owner));
        $this->assertTrue($this->permissionService->canReply($ticket, $owner));
        $this->assertTrue($this->permissionService->canManage($ticket, $owner));

        $this->assertTrue($this->permissionService->canView($ticket, $admin));
        $this->assertTrue($this->permissionService->canReply($ticket, $admin));
        $this->assertTrue($this->permissionService->canManage($ticket, $admin));
    }

    public function testOrganizationMemberOnlyViewsOwnTicketsUnlessGranted(): void
    {
        // Member 5 creates ticket in Org 100
        $ticketMember5 = $this->ticketService->createTicket([
            'user_id' => 5,
            'organization_id' => 100,
            'department_id' => $this->deptTech->getId(),
            'subject' => 'Member 5 private inquiry',
            'message' => 'Personal question',
        ]);

        // Member 6 (colleague in same org)
        $member6 = SupportUserContext::orgMember(userId: 6, organizationId: 100, canViewAll: false);

        // Member 6 CANNOT view colleague's ticket
        $this->assertFalse($this->permissionService->canView($ticketMember5, $member6));
        $this->assertFalse($this->permissionService->canReply($ticketMember5, $member6));

        // Member 5 CAN view and reply to their own ticket
        $member5 = SupportUserContext::orgMember(userId: 5, organizationId: 100, canViewAll: false);
        $this->assertTrue($this->permissionService->canView($ticketMember5, $member5));
        $this->assertTrue($this->permissionService->canReply($ticketMember5, $member5));

        // Member 7 with explicit canViewAll permission CAN view colleague's ticket
        $member7 = SupportUserContext::orgMember(userId: 7, organizationId: 100, canViewAll: true);
        $this->assertTrue($this->permissionService->canView($ticketMember5, $member7));
        $this->assertTrue($this->permissionService->canReply($ticketMember5, $member7));
    }

    public function testPersonalIndividualTicketsAccess(): void
    {
        // Personal ticket with no organization
        $ticketPersonal = $this->ticketService->createTicket([
            'user_id' => 50,
            'organization_id' => null,
            'department_id' => $this->deptTech->getId(),
            'subject' => 'Personal hosting plan',
            'message' => 'Help with cPanel',
        ]);

        $owner = SupportUserContext::individual(userId: 50);
        $otherUser = SupportUserContext::individual(userId: 51);

        $this->assertTrue($this->permissionService->canView($ticketPersonal, $owner));
        $this->assertTrue($this->permissionService->canReply($ticketPersonal, $owner));

        $this->assertFalse($this->permissionService->canView($ticketPersonal, $otherUser));
        $this->assertFalse($this->permissionService->canReply($ticketPersonal, $otherUser));
    }

    public function testStaffAndSuperadminAccessControls(): void
    {
        $ticketTech = $this->ticketService->createTicket([
            'user_id' => 10,
            'department_id' => $this->deptTech->getId(),
            'subject' => 'Technical issue',
            'message' => 'Error on server',
        ]);

        $ticketSales = $this->ticketService->createTicket([
            'user_id' => 10,
            'department_id' => $this->deptSales->getId(),
            'subject' => 'Sales quote',
            'message' => 'Discount request',
        ]);

        $superAdmin = SupportUserContext::superAdmin(userId: 999);
        $this->assertTrue($this->permissionService->canView($ticketTech, $superAdmin));
        $this->assertTrue($this->permissionService->canView($ticketSales, $superAdmin));
        $this->assertTrue($this->permissionService->canReply($ticketTech, $superAdmin));

        // Agent scoped strictly to Tech Department
        $agentTech = SupportUserContext::supportAgent(
            userId: 88,
            departmentIds: [$this->deptTech->getId()],
            permissions: ['tickets.view', 'tickets.reply']
        );

        $this->assertTrue($this->permissionService->canView($ticketTech, $agentTech));
        $this->assertTrue($this->permissionService->canReply($ticketTech, $agentTech));

        // Agent Tech CANNOT view Sales ticket due to department scoping
        $this->assertFalse($this->permissionService->canView($ticketSales, $agentTech));

        // Agent without reply permission
        $viewOnlyAgent = SupportUserContext::supportAgent(
            userId: 89,
            departmentIds: [$this->deptTech->getId()],
            permissions: ['tickets.view']
        );
        $this->assertTrue($this->permissionService->canView($ticketTech, $viewOnlyAgent));
        $this->assertFalse($this->permissionService->canReply($ticketTech, $viewOnlyAgent));
    }

    public function testAuthorizedQueryFilterGeneration(): void
    {
        $superAdmin = SupportUserContext::superAdmin(1);
        $this->assertSame([], $this->permissionService->getAuthorizedQueryFilters($superAdmin));

        $orgOwner = SupportUserContext::orgOwner(userId: 2, organizationId: 500);
        $this->assertSame(['organization_id' => 500], $this->permissionService->getAuthorizedQueryFilters($orgOwner));

        $orgMember = SupportUserContext::orgMember(userId: 3, organizationId: 500, canViewAll: false);
        $this->assertSame(
            ['organization_id' => 500, 'user_id' => 3],
            $this->permissionService->getAuthorizedQueryFilters($orgMember)
        );

        $individual = SupportUserContext::individual(userId: 4);
        $this->assertSame(['user_id' => 4], $this->permissionService->getAuthorizedQueryFilters($individual));
    }

    public function testFilterAuthorizedTicketsArray(): void
    {
        $t1 = $this->ticketService->createTicket([
            'user_id' => 10,
            'organization_id' => 100,
            'department_id' => $this->deptTech->getId(),
            'subject' => 'T1 Org 100 User 10',
            'message' => 'M1',
        ]);

        $t2 = $this->ticketService->createTicket([
            'user_id' => 20,
            'organization_id' => 100,
            'department_id' => $this->deptTech->getId(),
            'subject' => 'T2 Org 100 User 20',
            'message' => 'M2',
        ]);

        $t3 = $this->ticketService->createTicket([
            'user_id' => 30,
            'organization_id' => 200,
            'department_id' => $this->deptTech->getId(),
            'subject' => 'T3 Org 200 User 30',
            'message' => 'M3',
        ]);

        // Org 100 Member 10 (without view all) should see ONLY t1
        $member10 = SupportUserContext::orgMember(10, 100, canViewAll: false);
        $filtered = $this->permissionService->filterAuthorizedTickets([$t1, $t2, $t3], $member10);
        $this->assertCount(1, $filtered);
        $this->assertSame($t1->getId(), $filtered[0]->getId());

        // Org 100 Owner should see t1 and t2 (NOT t3 from Org 200!)
        $owner100 = SupportUserContext::orgOwner(99, 100);
        $filteredOwner = $this->permissionService->filterAuthorizedTickets([$t1, $t2, $t3], $owner100);
        $this->assertCount(2, $filteredOwner);
        $this->assertSame($t1->getId(), $filteredOwner[0]->getId());
        $this->assertSame($t2->getId(), $filteredOwner[1]->getId());
    }
}
