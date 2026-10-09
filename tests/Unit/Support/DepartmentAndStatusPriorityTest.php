<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Support;

use Coleza\Domain\Support\Departments\Department;
use Coleza\Domain\Support\Departments\DepartmentAgent;
use Coleza\Domain\Support\Departments\DepartmentService;
use Coleza\Domain\Support\Tickets\TicketPriority;
use Coleza\Domain\Support\Tickets\TicketStatus;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class DepartmentAndStatusPriorityTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private DepartmentService $service;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($this->pdo, 'sqlite');

        $this->service = new DepartmentService($this->db);
        $this->service->ensureTables();
    }

    public function testDepartmentCreationAndRetrieval(): void
    {
        $dep = $this->service->createDepartment([
            'name' => 'Technical Support',
            'description' => 'Help with servers and hosting infrastructure',
            'email' => 'tech@colezahost.com',
            'is_public' => true,
            'is_active' => true,
            'sort_order' => 10,
            'metadata' => ['tier' => 'L2', 'auto_assign' => true],
        ]);

        $this->assertGreaterThan(0, $dep->getId());
        $this->assertSame('Technical Support', $dep->getName());
        $this->assertSame('Help with servers and hosting infrastructure', $dep->getDescription());
        $this->assertSame('tech@colezahost.com', $dep->getEmail());
        $this->assertTrue($dep->isPublic());
        $this->assertTrue($dep->isActive());
        $this->assertSame(10, $dep->getSortOrder());
        $this->assertSame(['tier' => 'L2', 'auto_assign' => true], $dep->getMetadata());

        $fetched = $this->service->getDepartment($dep->getId());
        $this->assertNotNull($fetched);
        $this->assertSame($dep->getId(), $fetched->getId());
        $this->assertSame('Technical Support', $fetched->getName());

        $byName = $this->service->findByName('Technical Support');
        $this->assertNotNull($byName);
        $this->assertSame($dep->getId(), $byName->getId());

        $arr = $dep->toArray();
        $this->assertSame('Technical Support', $arr['name']);
        $reconstructed = Department::fromArray($arr);
        $this->assertSame($dep->getId(), $reconstructed->getId());
        $this->assertSame($dep->getName(), $reconstructed->getName());
    }

    public function testDepartmentValidationFailures(): void
    {
        // Empty name
        $this->expectException(ValidationException::class);
        $this->service->createDepartment([
            'name' => '   ',
        ]);
    }

    public function testDuplicateDepartmentNamePrevention(): void
    {
        $this->service->createDepartment([
            'name' => 'Billing',
            'email' => 'billing@colezahost.com',
        ]);

        $this->expectException(ValidationException::class);
        $this->service->createDepartment([
            'name' => 'Billing',
        ]);
    }

    public function testInvalidDepartmentEmailValidation(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->createDepartment([
            'name' => 'Abuse & Legal',
            'email' => 'not-an-email',
        ]);
    }

    public function testDepartmentUpdate(): void
    {
        $dep = $this->service->createDepartment([
            'name' => 'Customer Care',
            'email' => 'care@colezahost.com',
            'is_public' => true,
            'is_active' => true,
        ]);

        $updated = $this->service->updateDepartment($dep->getId(), [
            'name' => 'Customer Operations',
            'description' => 'Updated care team description',
            'email' => 'ops@colezahost.com',
            'is_public' => false,
            'is_active' => true,
            'sort_order' => 25,
            'metadata' => ['key' => 'val'],
        ]);

        $this->assertSame('Customer Operations', $updated->getName());
        $this->assertSame('Updated care team description', $updated->getDescription());
        $this->assertSame('ops@colezahost.com', $updated->getEmail());
        $this->assertFalse($updated->isPublic());
        $this->assertSame(25, $updated->getSortOrder());
        $this->assertSame(['key' => 'val'], $updated->getMetadata());
    }

    public function testDepartmentListingFilters(): void
    {
        $this->service->createDepartment([
            'name' => 'Dept Public Active',
            'is_public' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $this->service->createDepartment([
            'name' => 'Dept Public Inactive',
            'is_public' => true,
            'is_active' => false,
            'sort_order' => 2,
        ]);
        $this->service->createDepartment([
            'name' => 'Dept Internal Active',
            'is_public' => false,
            'is_active' => true,
            'sort_order' => 3,
        ]);

        $all = $this->service->listDepartments();
        $this->assertCount(3, $all);

        $onlyActive = $this->service->listDepartments(onlyActive: true);
        $this->assertCount(2, $onlyActive);

        $onlyPublic = $this->service->listDepartments(onlyActive: false, onlyPublic: true);
        $this->assertCount(2, $onlyPublic);

        $publicActive = $this->service->listDepartments(onlyActive: true, onlyPublic: true);
        $this->assertCount(1, $publicActive);
        $this->assertSame('Dept Public Active', $publicActive[0]->getName());
    }

    public function testDepartmentDeletionSafeguards(): void
    {
        $dep = $this->service->createDepartment(['name' => 'Sales Dept']);
        $this->service->assignAgent($dep->getId(), userId: 101, role: 'sales_rep');

        // Normal deletion without force fails due to active agents
        $this->expectException(ValidationException::class);
        $this->service->deleteDepartment($dep->getId(), force: false);
    }

    public function testDepartmentForceDeletion(): void
    {
        $dep = $this->service->createDepartment(['name' => 'Temporary Dept']);
        $this->service->assignAgent($dep->getId(), userId: 102);

        $deleted = $this->service->deleteDepartment($dep->getId(), force: true);
        $this->assertTrue($deleted);

        $this->assertNull($this->service->getDepartment($dep->getId()));
        $this->assertEmpty($this->service->getDepartmentAgents($dep->getId(), onlyActive: false));
    }

    public function testAgentAssignmentAndMembership(): void
    {
        $dep = $this->service->createDepartment(['name' => 'L1 Support']);

        $agent = $this->service->assignAgent(
            departmentId: $dep->getId(),
            userId: 55,
            role: 'lead',
            canAssign: true,
            receivesNotifications: true,
            metadata: ['skill' => 'linux']
        );

        $this->assertSame($dep->getId(), $agent->getDepartmentId());
        $this->assertSame(55, $agent->getUserId());
        $this->assertSame('lead', $agent->getRole());
        $this->assertTrue($agent->canAssign());
        $this->assertTrue($agent->receivesNotifications());
        $this->assertTrue($agent->isActive());
        $this->assertSame(['skill' => 'linux'], $agent->getMetadata());

        $this->assertTrue($this->service->isAgentInDepartment($dep->getId(), 55));
        $this->assertFalse($this->service->isAgentInDepartment($dep->getId(), 999));

        // Reassigning updates properties cleanly
        $updatedAgent = $this->service->assignAgent(
            departmentId: $dep->getId(),
            userId: 55,
            role: 'supervisor',
            canAssign: false,
            receivesNotifications: false
        );
        $this->assertSame('supervisor', $updatedAgent->getRole());
        $this->assertFalse($updatedAgent->canAssign());

        // Update membership helper
        $modified = $this->service->updateAgentMembership($dep->getId(), 55, [
            'can_assign' => true,
            'role' => 'senior_lead',
        ]);
        $this->assertSame('senior_lead', $modified->getRole());
        $this->assertTrue($modified->canAssign());

        // Test toArray and fromArray
        $arr = $modified->toArray();
        $this->assertSame(55, $arr['user_id']);
        $reconstructed = DepartmentAgent::fromArray($arr);
        $this->assertSame($modified->getId(), $reconstructed->getId());
    }

    public function testAssignableAgentsFiltering(): void
    {
        $dep = $this->service->createDepartment(['name' => 'Helpdesk']);

        // Agent 1: Active and can_assign = true
        $this->service->assignAgent($dep->getId(), userId: 1, canAssign: true);
        // Agent 2: Active but can_assign = false
        $this->service->assignAgent($dep->getId(), userId: 2, canAssign: false);
        // Agent 3: Inactive
        $this->service->assignAgent($dep->getId(), userId: 3, canAssign: true);
        $this->service->updateAgentMembership($dep->getId(), userId: 3, data: ['is_active' => false]);

        $assignable = $this->service->getAssignableAgents($dep->getId());
        $this->assertCount(1, $assignable);
        $this->assertSame(1, $assignable[0]->getUserId());

        $allAgents = $this->service->getDepartmentAgents($dep->getId(), onlyActive: false);
        $this->assertCount(3, $allAgents);

        $activeAgents = $this->service->getDepartmentAgents($dep->getId(), onlyActive: true);
        $this->assertCount(2, $activeAgents);
    }

    public function testAgentAcrossMultipleDepartments(): void
    {
        $dep1 = $this->service->createDepartment(['name' => 'Dept Alpha']);
        $dep2 = $this->service->createDepartment(['name' => 'Dept Beta']);

        $this->service->assignAgent($dep1->getId(), userId: 200);
        $this->service->assignAgent($dep2->getId(), userId: 200);

        $departmentsOfAgent = $this->service->getAgentDepartments(200);
        $this->assertCount(2, $departmentsOfAgent);

        // Remove from dep1
        $removed = $this->service->removeAgent($dep1->getId(), 200);
        $this->assertTrue($removed);
        $this->assertFalse($this->service->isAgentInDepartment($dep1->getId(), 200));
        $this->assertTrue($this->service->isAgentInDepartment($dep2->getId(), 200));
    }

    public function testTicketStatusStateMachine(): void
    {
        $statuses = TicketStatus::all();
        $this->assertContains(TicketStatus::OPEN, $statuses);
        $this->assertContains(TicketStatus::CUSTOMER_REPLY, $statuses);
        $this->assertContains(TicketStatus::IN_PROGRESS, $statuses);
        $this->assertContains(TicketStatus::ANSWERED, $statuses);
        $this->assertContains(TicketStatus::ON_HOLD, $statuses);
        $this->assertContains(TicketStatus::RESOLVED, $statuses);
        $this->assertContains(TicketStatus::CLOSED, $statuses);

        // Category queries
        $this->assertTrue(TicketStatus::isOpen(TicketStatus::OPEN));
        $this->assertTrue(TicketStatus::isOpen(TicketStatus::IN_PROGRESS));
        $this->assertFalse(TicketStatus::isOpen(TicketStatus::CLOSED));
        $this->assertFalse(TicketStatus::isOpen(TicketStatus::RESOLVED));

        $this->assertTrue(TicketStatus::isWaitingStaff(TicketStatus::OPEN));
        $this->assertTrue(TicketStatus::isWaitingStaff(TicketStatus::CUSTOMER_REPLY));
        $this->assertFalse(TicketStatus::isWaitingStaff(TicketStatus::ANSWERED));

        $this->assertTrue(TicketStatus::isWaitingCustomer(TicketStatus::ANSWERED));
        $this->assertTrue(TicketStatus::isPaused(TicketStatus::ON_HOLD));
        $this->assertTrue(TicketStatus::isResolved(TicketStatus::RESOLVED));
        $this->assertTrue(TicketStatus::isClosed(TicketStatus::CLOSED));
        $this->assertTrue(TicketStatus::isTerminal(TicketStatus::CLOSED));

        // Allowed transitions
        $this->assertTrue(TicketStatus::canTransition(TicketStatus::OPEN, TicketStatus::IN_PROGRESS));
        $this->assertTrue(TicketStatus::canTransition(TicketStatus::IN_PROGRESS, TicketStatus::ANSWERED));
        $this->assertTrue(TicketStatus::canTransition(TicketStatus::ANSWERED, TicketStatus::CUSTOMER_REPLY));
        $this->assertTrue(TicketStatus::canTransition(TicketStatus::ANSWERED, TicketStatus::RESOLVED));
        $this->assertTrue(TicketStatus::canTransition(TicketStatus::RESOLVED, TicketStatus::CLOSED));

        // Closed ticket reopen transitions
        $this->assertTrue(TicketStatus::canTransition(TicketStatus::CLOSED, TicketStatus::OPEN));
        $this->assertTrue(TicketStatus::canTransition(TicketStatus::CLOSED, TicketStatus::CUSTOMER_REPLY));
        $this->assertFalse(TicketStatus::canTransition(TicketStatus::CLOSED, TicketStatus::IN_PROGRESS));

        // Idempotent self-transition
        $this->assertTrue(TicketStatus::canTransition(TicketStatus::IN_PROGRESS, TicketStatus::IN_PROGRESS));

        // Invalid transitions assert exception
        TicketStatus::assertValidTransition(TicketStatus::OPEN, TicketStatus::IN_PROGRESS);

        $this->expectException(ValidationException::class);
        TicketStatus::assertValidTransition(TicketStatus::CLOSED, TicketStatus::ANSWERED);
    }

    public function testTicketStatusLocalizationAndBadges(): void
    {
        $this->assertSame('Open', TicketStatus::getLabel(TicketStatus::OPEN, 'en'));
        $this->assertSame('Açık', TicketStatus::getLabel(TicketStatus::OPEN, 'tr'));
        $this->assertSame('Customer-Reply', TicketStatus::getLabel(TicketStatus::CUSTOMER_REPLY, 'en'));
        $this->assertSame('Müşteri Yanıtladı', TicketStatus::getLabel(TicketStatus::CUSTOMER_REPLY, 'tr'));
        $this->assertSame('Kapatıldı', TicketStatus::getLabel(TicketStatus::CLOSED, 'tr'));

        $this->assertSame('badge-danger', TicketStatus::getBadgeClass(TicketStatus::OPEN));
        $this->assertSame('badge-success', TicketStatus::getBadgeClass(TicketStatus::RESOLVED));
        $this->assertSame('badge-dark', TicketStatus::getBadgeClass(TicketStatus::CLOSED));
    }

    public function testTicketPriorityWeightsAndSlaDefaults(): void
    {
        $priorities = TicketPriority::all();
        $this->assertCount(4, $priorities);
        $this->assertContains(TicketPriority::LOW, $priorities);
        $this->assertContains(TicketPriority::MEDIUM, $priorities);
        $this->assertContains(TicketPriority::HIGH, $priorities);
        $this->assertContains(TicketPriority::CRITICAL, $priorities);

        $this->assertTrue(TicketPriority::isValid('critical'));
        $this->assertFalse(TicketPriority::isValid('urgent_unknown'));

        $this->assertTrue(TicketPriority::isHigherThan(TicketPriority::CRITICAL, TicketPriority::HIGH));
        $this->assertTrue(TicketPriority::isHigherThan(TicketPriority::HIGH, TicketPriority::MEDIUM));
        $this->assertTrue(TicketPriority::isHigherThan(TicketPriority::MEDIUM, TicketPriority::LOW));
        $this->assertFalse(TicketPriority::isHigherThan(TicketPriority::LOW, TicketPriority::CRITICAL));

        // SLA targets (in minutes)
        $this->assertSame(60, TicketPriority::getDefaultFirstResponseMinutes(TicketPriority::CRITICAL));
        $this->assertSame(240, TicketPriority::getDefaultFirstResponseMinutes(TicketPriority::HIGH));
        $this->assertSame(720, TicketPriority::getDefaultFirstResponseMinutes(TicketPriority::MEDIUM));
        $this->assertSame(1440, TicketPriority::getDefaultFirstResponseMinutes(TicketPriority::LOW));

        $this->assertSame(240, TicketPriority::getDefaultResolutionMinutes(TicketPriority::CRITICAL));
        $this->assertSame(720, TicketPriority::getDefaultResolutionMinutes(TicketPriority::HIGH));
        $this->assertSame(1440, TicketPriority::getDefaultResolutionMinutes(TicketPriority::MEDIUM));
        $this->assertSame(2880, TicketPriority::getDefaultResolutionMinutes(TicketPriority::LOW));

        // Localization
        $this->assertSame('Critical', TicketPriority::getLabel(TicketPriority::CRITICAL, 'en'));
        $this->assertSame('Kritik', TicketPriority::getLabel(TicketPriority::CRITICAL, 'tr'));
        $this->assertSame('Düşük', TicketPriority::getLabel(TicketPriority::LOW, 'tr'));

        // Badges
        $this->assertSame('badge-danger', TicketPriority::getBadgeClass(TicketPriority::CRITICAL));
        $this->assertSame('badge-secondary', TicketPriority::getBadgeClass(TicketPriority::LOW));

        // Assert valid
        TicketPriority::assertValid(TicketPriority::HIGH);
        $this->expectException(ValidationException::class);
        TicketPriority::assertValid('invalid_priority');
    }
}
