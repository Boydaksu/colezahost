<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Support;

use Coleza\Domain\Support\Announcements\Announcement;
use Coleza\Domain\Support\Announcements\AnnouncementService;
use Coleza\Domain\Support\Assignments\TicketAssignmentService;
use Coleza\Domain\Support\Attachments\AttachmentSecurityPolicy;
use Coleza\Domain\Support\Attachments\AttachmentService;
use Coleza\Domain\Support\Attachments\InMemoryAttachmentStorage;
use Coleza\Domain\Support\CannedResponses\CannedResponseService;
use Coleza\Domain\Support\Context\InMemoryTicketContextProvider;
use Coleza\Domain\Support\Context\TicketContextService;
use Coleza\Domain\Support\Departments\DepartmentService;
use Coleza\Domain\Support\Permissions\SupportUserContext;
use Coleza\Domain\Support\Permissions\TicketPermissionService;
use Coleza\Domain\Support\Sla\SlaService;
use Coleza\Domain\Support\Sla\TicketSla;
use Coleza\Domain\Support\Tickets\TicketPriority;
use Coleza\Domain\Support\Tickets\TicketService;
use Coleza\Domain\Support\Tickets\TicketStatus;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class SupportGoldenE2ETest extends TestCase
{
    private Connection $db;
    private PDO $pdo;

    // Services
    private DepartmentService $departmentService;
    private TicketService $ticketService;
    private AttachmentService $attachmentService;
    private InMemoryAttachmentStorage $attachmentStorage;
    private TicketAssignmentService $assignmentService;
    private SlaService $slaService;
    private InMemoryTicketContextProvider $contextProvider;
    private TicketContextService $contextService;
    private TicketPermissionService $permissionService;
    private CannedResponseService $cannedService;
    private AnnouncementService $announcementService;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($this->pdo, 'sqlite');

        // Initialize all Support Domain services
        $this->departmentService = new DepartmentService($this->db);
        $this->departmentService->ensureTables();

        $this->ticketService = new TicketService($this->db, $this->departmentService);
        $this->ticketService->ensureTables();

        $this->attachmentStorage = new InMemoryAttachmentStorage();
        $this->attachmentService = new AttachmentService(
            $this->db,
            $this->attachmentStorage,
            new AttachmentSecurityPolicy(maxSizeBytes: 10 * 1024 * 1024)
        );
        $this->attachmentService->ensureTables();

        $this->assignmentService = new TicketAssignmentService($this->db, $this->departmentService, $this->ticketService);
        $this->assignmentService->ensureTables();

        $this->slaService = new SlaService($this->db, $this->ticketService);
        $this->slaService->ensureTables();

        $this->contextProvider = new InMemoryTicketContextProvider();
        $this->contextService = new TicketContextService($this->db, $this->ticketService, $this->contextProvider);
        $this->contextService->ensureTables();

        $this->permissionService = new TicketPermissionService();

        $this->cannedService = new CannedResponseService($this->db, $this->departmentService);
        $this->cannedService->ensureTables();

        $this->announcementService = new AnnouncementService($this->db);
        $this->announcementService->ensureTables();
    }

    public function testComprehensiveSupportDeskGoldenLifecycle(): void
    {
        $t0 = new DateTimeImmutable('2026-10-09 09:00:00');

        // =====================================================================
        // STEP 1: Department & Staff Configuration
        // =====================================================================
        $deptTech = $this->departmentService->createDepartment([
            'name' => 'Technical Operations',
            'description' => '24/7 Level 2 & 3 Server and Infrastructure Engineering',
            'email' => 'techops@colezahost.com',
            'is_public' => true,
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $deptBilling = $this->departmentService->createDepartment([
            'name' => 'Billing & Accounts',
            'description' => 'Invoices, payment gateways, and renewals',
            'email' => 'billing@colezahost.com',
            'is_public' => true,
            'is_active' => true,
            'sort_order' => 20,
        ]);

        // Assign staff agents
        $agent101 = $this->departmentService->assignAgent($deptTech->getId(), userId: 101, role: 'lead', canAssign: true);
        $agent102 = $this->departmentService->assignAgent($deptTech->getId(), userId: 102, role: 'agent', canAssign: true);
        $agent201 = $this->departmentService->assignAgent($deptBilling->getId(), userId: 201, role: 'agent', canAssign: true);

        $this->assertTrue($this->departmentService->isAgentInDepartment($deptTech->getId(), 101));
        $this->assertTrue($this->departmentService->isAgentInDepartment($deptTech->getId(), 102));
        $this->assertFalse($this->departmentService->isAgentInDepartment($deptTech->getId(), 201));

        // Create standard Canned Response template in Tech department
        $this->cannedService->create([
            'title' => 'Service Restored Notification',
            'shortcut' => '/service-restored',
            'content' => "Hello {{ client_name }},\n\nWe have investigated ticket #{{ ticket_number }}. Upstream connectivity and services on server node {{ server_name }} have been fully restored.\n\nPlease verify on your end.",
            'department_id' => $deptTech->getId(),
        ]);

        // =====================================================================
        // STEP 2: Customer Ticket Ingestion with Cross-Domain Relations
        // =====================================================================
        $customerUserId = 50;
        $customerOrgId = 100;

        $ticket = $this->ticketService->createTicket([
            'user_id' => $customerUserId,
            'organization_id' => $customerOrgId,
            'department_id' => $deptTech->getId(),
            'subject' => 'CRITICAL: Production Nginx 502 Bad Gateway Outage',
            'message' => 'All frontend web requests are failing with HTTP 502 Bad Gateway.',
            'priority' => TicketPriority::CRITICAL,
            'service_id' => 5001,
            'domain_id' => 6002,
            'invoice_id' => 7003,
            'order_id' => 8004,
        ]);

        $this->assertGreaterThan(0, $ticket->getId());
        $this->assertStringStartsWith('TIC-', $ticket->getTicketNumber());
        $this->assertSame(TicketStatus::OPEN, $ticket->getStatus());
        $this->assertSame(TicketPriority::CRITICAL, $ticket->getPriority());
        $this->assertSame($customerOrgId, $ticket->getOrganizationId());
        $this->assertSame(5001, $ticket->getServiceId());
        $this->assertSame(6002, $ticket->getDomainId());
        $this->assertSame(7003, $ticket->getInvoiceId());
        $this->assertSame(8004, $ticket->getOrderId());

        // Verify initial message in conversation
        $conversation0 = $this->ticketService->getConversation($ticket->getId());
        $this->assertCount(1, $conversation0);
        $this->assertSame('All frontend web requests are failing with HTTP 502 Bad Gateway.', $conversation0[0]->getMessage());
        $this->assertFalse($conversation0[0]->isStaff());

        // =====================================================================
        // STEP 3: SLA Engine Initialization
        // =====================================================================
        $sla = $this->slaService->initializeTicketSla($ticket->getId(), startTime: $t0);
        $this->assertSame(TicketSla::STATUS_ACTIVE, $sla->getStatus());

        // Critical standard target: 60 mins first response (10:00:00), 240 mins resolution (13:00:00)
        $this->assertSame('2026-10-09 10:00:00', $sla->getFirstResponseDueAt()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-09 13:00:00', $sla->getResolutionDueAt()->format('Y-m-d H:i:s'));

        // =====================================================================
        // STEP 4: Automated Dispatch & Workload Balancing
        // =====================================================================
        $assignmentLog = $this->assignmentService->assignRoundRobin($ticket->getId(), actorUserId: null, reason: 'Auto triage dispatch');
        $this->assertNotNull($assignmentLog);
        $this->assertSame(101, $assignmentLog->getNewAssignedTo());

        $assignedTicket = $this->ticketService->requireTicket($ticket->getId());
        $this->assertSame(101, $assignedTicket->getAssignedTo());
        $this->assertTrue($assignedTicket->isAssigned());

        // =====================================================================
        // STEP 5: Secure Attachment Upload by Customer
        // =====================================================================
        $logPayload = "2026-10-09 09:05:00 [error] 1420#1420: *120 connect() failed (111: Connection refused) while connecting to upstream\nStack trace complete.";
        $custAttachment = $this->attachmentService->attachFile(
            ticketId: $ticket->getId(),
            userId: $customerUserId,
            filename: 'nginx_error.log',
            content: $logPayload,
            messageId: $conversation0[0]->getId(),
            isPrivate: false
        );

        $this->assertSame('nginx_error.log', $custAttachment->getOriginalFilename());
        $this->assertSame(hash('sha256', $logPayload), $custAttachment->getSha256Hash());
        $this->assertFalse($custAttachment->isPrivate());

        // Verify byte-level integrity verification
        $verifiedBytes = $this->attachmentService->readContent($custAttachment->getId());
        $this->assertSame($logPayload, $verifiedBytes);

        // =====================================================================
        // STEP 6: Decoupled Context Provider & Contextual Commands
        // =====================================================================
        $this->contextProvider->setService(5001, [
            'id' => 5001,
            'package_name' => 'cPanel Cloud Dedicated Node',
            'server_name' => 'node-fra-02',
            'ip_address' => '198.51.100.25',
            'status' => 'active',
        ]);
        $this->contextProvider->setDomain(6002, [
            'id' => 6002,
            'domain_name' => 'colezastore.com',
            'status' => 'active',
            'registrar' => 'NameSilo',
        ]);
        $this->contextProvider->setInvoice(7003, [
            'id' => 7003,
            'invoice_number' => 'INV-2026-0099',
            'status' => 'paid',
            'total' => '$199.00',
        ]);

        $contextSummary = $this->contextService->getContextSummary($ticket->getId());
        $this->assertTrue($contextSummary->hasService());
        $this->assertTrue($contextSummary->hasDomain());
        $this->assertTrue($contextSummary->hasInvoice());
        $this->assertSame('node-fra-02', $contextSummary->service['server_name']);
        $this->assertSame('colezastore.com', $contextSummary->domain['domain_name']);

        // Staff triggers contextual command
        $probeCmd = $this->contextService->executeCommand(
            ticketId: $ticket->getId(),
            actorStaffUserId: 101,
            action: 'service.sync_status'
        );
        $this->assertTrue($probeCmd->success);

        $rebootCmd = $this->contextService->executeCommand(
            ticketId: $ticket->getId(),
            actorStaffUserId: 101,
            action: 'service.reboot'
        );
        $this->assertTrue($rebootCmd->success);
        $this->assertCount(2, $this->contextService->getCommandLogs($ticket->getId()));

        // =====================================================================
        // STEP 7: Private Internal Notes & Private Attachments
        // =====================================================================
        $internalNote = $this->ticketService->addInternalNote(
            ticketId: $ticket->getId(),
            staffUserId: 101,
            message: 'CONFIDENTIAL: PHP-FPM pool crashed due to memory exhaustion. Restarting service with increased memory_limit.'
        );
        $this->assertTrue($internalNote->isInternalNote());
        $this->assertTrue($internalNote->isStaff());

        // Staff attaches private diagnostic memory dump
        $privateDump = $this->attachmentService->attachFile(
            ticketId: $ticket->getId(),
            userId: 101,
            filename: 'fpm_core_dump.log',
            content: 'INTERNAL FPM MEMORY PROFILE: 512MB threshold exceeded',
            messageId: $internalNote->getId(),
            isPrivate: true
        );
        $this->assertTrue($privateDump->isPrivate());

        // Verify customer view excludes internal notes and private attachments!
        $customerConversation = $this->ticketService->getConversation($ticket->getId(), includeInternalNotes: false);
        $this->assertCount(1, $customerConversation); // Only initial inquiry

        $customerAttachments = $this->attachmentService->getTicketAttachments($ticket->getId(), includePrivate: false);
        $this->assertCount(1, $customerAttachments);
        $this->assertSame($custAttachment->getId(), $customerAttachments[0]->getId());

        // Staff view includes both internal notes and private attachments!
        $staffConversation = $this->ticketService->getConversation($ticket->getId(), includeInternalNotes: true);
        $this->assertCount(2, $staffConversation);

        $staffAttachments = $this->attachmentService->getTicketAttachments($ticket->getId(), includePrivate: true);
        $this->assertCount(2, $staffAttachments);

        // =====================================================================
        // STEP 8: Canned Response Reply & First Response SLA Met
        // =====================================================================
        $canned = $this->cannedService->findByShortcut('/service-restored', $deptTech->getId());
        $this->assertNotNull($canned);

        $renderedReply = $canned->render([
            'client_name' => 'Acme Cloud Corp',
            'ticket_number' => $ticket->getTicketNumber(),
            'server_name' => 'node-fra-02',
        ]);
        $this->assertStringContainsString('ticket #' . $ticket->getTicketNumber(), $renderedReply);
        $this->assertStringContainsString('node-fra-02', $renderedReply);

        // Staff sends public reply at 09:25:00 (within 60m SLA target)
        $staffReplyTime = new DateTimeImmutable('2026-10-09 09:25:00');
        $staffReply = $this->ticketService->addReply(
            ticketId: $ticket->getId(),
            userId: 101,
            message: $renderedReply,
            isStaff: true
        );
        $this->assertTrue($staffReply->isStaff());

        // Ticket status is now answered
        $reloadedTicket1 = $this->ticketService->requireTicket($ticket->getId());
        $this->assertSame(TicketStatus::ANSWERED, $reloadedTicket1->getStatus());
        $this->assertTrue($reloadedTicket1->isLastReplyByStaff());

        // Record SLA first response
        $slaUpdated = $this->slaService->recordFirstResponse($ticket->getId(), $staffReplyTime);
        $this->assertNotNull($slaUpdated);
        $this->assertTrue($slaUpdated->isFirstResponseMet());
        $this->assertFalse($slaUpdated->isFirstResponseBreached());

        // =====================================================================
        // STEP 9: Hold Period SLA Pause and Resume
        // =====================================================================
        // Staff puts ticket on hold at 09:30:00 (waiting for customer confirmation)
        $this->ticketService->updateStatus($ticket->getId(), TicketStatus::ON_HOLD);
        $this->slaService->pauseSla($ticket->getId(), new DateTimeImmutable('2026-10-09 09:30:00'));

        $slaPaused = $this->slaService->getTicketSla($ticket->getId());
        $this->assertTrue($slaPaused->isPaused());

        // Resume at 10:30:00 (1 hour paused = 3600 seconds)
        $slaResumed = $this->slaService->resumeSla($ticket->getId(), new DateTimeImmutable('2026-10-09 10:30:00'));
        $this->assertFalse($slaResumed->isPaused());
        $this->assertSame(3600, $slaResumed->getTotalPausedSeconds());
        // Resolution deadline shifted from 13:00:00 to 14:00:00
        $this->assertSame('2026-10-09 14:00:00', $slaResumed->getResolutionDueAt()->format('Y-m-d H:i:s'));

        // =====================================================================
        // STEP 10: Customer Reopen, Resolution & Ticket Closure
        // =====================================================================
        // Customer confirms resolution at 10:45:00
        $custConfirmation = $this->ticketService->addReply(
            ticketId: $ticket->getId(),
            userId: $customerUserId,
            message: 'All websites are operating normally now. Excellent service!',
            isStaff: false
        );
        $this->assertFalse($custConfirmation->isStaff());

        // Ticket automatically transitioned to customer_reply
        $reloadedTicket2 = $this->ticketService->requireTicket($ticket->getId());
        $this->assertSame(TicketStatus::CUSTOMER_REPLY, $reloadedTicket2->getStatus());
        $this->assertFalse($reloadedTicket2->isLastReplyByStaff());

        // Staff resolves ticket at 11:00:00 (well before shifted resolution due at 14:00:00)
        $resolutionTime = new DateTimeImmutable('2026-10-09 11:00:00');
        $this->ticketService->updateStatus($ticket->getId(), TicketStatus::RESOLVED);
        $slaResolved = $this->slaService->recordResolution($ticket->getId(), $resolutionTime);

        $this->assertSame(TicketSla::STATUS_MET, $slaResolved->getStatus());
        $this->assertTrue($slaResolved->isResolutionMet());
        $this->assertFalse($slaResolved->isAnyBreached());

        // Finally, ticket is closed
        $closedTicket = $this->ticketService->updateStatus($ticket->getId(), TicketStatus::CLOSED);
        $this->assertTrue($closedTicket->isClosed());
        $this->assertNotNull($closedTicket->getClosedAt());

        // =====================================================================
        // STEP 11: Multi-Tenant Permission Isolation Barrier
        // =====================================================================
        // 1. Foreign organization owner (Org 200)
        $foreignOrgOwner = SupportUserContext::orgOwner(userId: 888, organizationId: 200);
        $this->assertFalse($this->permissionService->canView($closedTicket, $foreignOrgOwner));
        $this->assertFalse($this->permissionService->canReply($closedTicket, $foreignOrgOwner));
        $this->assertFalse($this->permissionService->canManage($closedTicket, $foreignOrgOwner));

        // 2. Foreign organization member (Org 200)
        $foreignOrgMember = SupportUserContext::orgMember(userId: 889, organizationId: 200);
        $this->assertFalse($this->permissionService->canView($closedTicket, $foreignOrgMember));

        // 3. Same organization owner (Org 100)
        $org100Owner = SupportUserContext::orgOwner(userId: 1, organizationId: 100);
        $this->assertTrue($this->permissionService->canView($closedTicket, $org100Owner));
        $this->assertTrue($this->permissionService->canManage($closedTicket, $org100Owner));

        // 4. Same organization colleague without view_all (Org 100, user 51)
        $colleagueWithoutPerm = SupportUserContext::orgMember(userId: 51, organizationId: 100, canViewAll: false);
        $this->assertFalse($this->permissionService->canView($closedTicket, $colleagueWithoutPerm));

        // 5. Creator user (Org 100, user 50)
        $creatorUser = SupportUserContext::orgMember(userId: 50, organizationId: 100, canViewAll: false);
        $this->assertTrue($this->permissionService->canView($closedTicket, $creatorUser));

        // 6. Superadmin
        $superAdmin = SupportUserContext::superAdmin(userId: 999);
        $this->assertTrue($this->permissionService->canView($closedTicket, $superAdmin));

        // =====================================================================
        // STEP 12: Broadcast Service Desk Announcement
        // =====================================================================
        $announcement = $this->announcementService->create([
            'title' => 'Frankfurt Core Switch Maintenance Completed',
            'content' => 'All maintenance work has completed ahead of schedule with zero residual packet loss.',
            'type' => Announcement::TYPE_INFO,
            'is_pinned' => true,
            'is_public' => true,
            'published_at' => new DateTimeImmutable('2026-10-09 11:05:00'),
        ]);

        $liveAnnouncements = $this->announcementService->listLiveAnnouncements(
            onlyPublic: true,
            now: new DateTimeImmutable('2026-10-09 11:10:00')
        );
        $this->assertCount(1, $liveAnnouncements);
        $this->assertSame($announcement->getId(), $liveAnnouncements[0]->getId());
        $this->assertTrue($liveAnnouncements[0]->isPinned());

        // Golden E2E journey successfully completed with 100% integrity!
    }
}
