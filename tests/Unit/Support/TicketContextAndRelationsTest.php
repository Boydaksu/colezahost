<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Support;

use Coleza\Domain\Support\Context\CommandExecutionResult;
use Coleza\Domain\Support\Context\InMemoryTicketContextProvider;
use Coleza\Domain\Support\Context\TicketContextCommandHandlerInterface;
use Coleza\Domain\Support\Context\TicketContextService;
use Coleza\Domain\Support\Departments\Department;
use Coleza\Domain\Support\Departments\DepartmentService;
use Coleza\Domain\Support\Tickets\Ticket;
use Coleza\Domain\Support\Tickets\TicketService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class TicketContextAndRelationsTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private DepartmentService $departmentService;
    private TicketService $ticketService;
    private InMemoryTicketContextProvider $contextProvider;
    private TicketContextService $contextService;
    private Department $deptTech;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($this->pdo, 'sqlite');

        $this->departmentService = new DepartmentService($this->db);
        $this->departmentService->ensureTables();

        $this->ticketService = new TicketService($this->db, $this->departmentService);
        $this->ticketService->ensureTables();

        $this->contextProvider = new InMemoryTicketContextProvider();
        $this->contextService = new TicketContextService($this->db, $this->ticketService, $this->contextProvider);
        $this->contextService->ensureTables();

        $this->deptTech = $this->departmentService->createDepartment(['name' => 'Technical Operations']);
    }

    public function testTicketCreationWithAllResourceRelations(): void
    {
        $ticket = $this->ticketService->createTicket([
            'user_id' => 10,
            'department_id' => $this->deptTech->getId(),
            'subject' => 'cPanel SSL Renewal Failed',
            'message' => 'AutoSSL could not verify domain for my service.',
            'service_id' => 5001,
            'domain_id' => 6002,
            'invoice_id' => 7003,
            'order_id' => 8004,
        ]);

        $this->assertSame(5001, $ticket->getServiceId());
        $this->assertSame(6002, $ticket->getDomainId());
        $this->assertSame(7003, $ticket->getInvoiceId());
        $this->assertSame(8004, $ticket->getOrderId());

        $arr = $ticket->toArray();
        $this->assertSame(5001, $arr['service_id']);
        $this->assertSame(6002, $arr['domain_id']);
        $this->assertSame(7003, $arr['invoice_id']);
        $this->assertSame(8004, $arr['order_id']);

        $reconstructed = Ticket::fromArray($arr);
        $this->assertSame(5001, $reconstructed->getServiceId());
        $this->assertSame(6002, $reconstructed->getDomainId());
        $this->assertSame(7003, $reconstructed->getInvoiceId());
        $this->assertSame(8004, $reconstructed->getOrderId());
    }

    public function testDynamicResourceLinkingAndUnlinking(): void
    {
        $ticket = $this->ticketService->createTicket([
            'user_id' => 10,
            'department_id' => $this->deptTech->getId(),
            'subject' => 'General inquiry',
            'message' => 'Please link my service later',
        ]);

        $this->assertNull($ticket->getServiceId());
        $this->assertNull($ticket->getDomainId());
        $this->assertNull($ticket->getInvoiceId());
        $this->assertNull($ticket->getOrderId());

        // Link service
        $t1 = $this->ticketService->linkResource($ticket->getId(), 'service', 111);
        $this->assertSame(111, $t1->getServiceId());

        // Link domain
        $t2 = $this->ticketService->linkResource($ticket->getId(), 'domain', 222);
        $this->assertSame(222, $t2->getDomainId());

        // Link invoice
        $t3 = $this->ticketService->linkResource($ticket->getId(), 'invoice', 333);
        $this->assertSame(333, $t3->getInvoiceId());

        // Link order
        $t4 = $this->ticketService->linkResource($ticket->getId(), 'order', 444);
        $this->assertSame(444, $t4->getOrderId());

        // Unlink domain
        $t5 = $this->ticketService->unlinkResource($ticket->getId(), 'domain');
        $this->assertNull($t5->getDomainId());
        $this->assertSame(111, $t5->getServiceId()); // other resources preserved

        // Invalid resource type
        $this->expectException(ValidationException::class);
        $this->ticketService->linkResource($ticket->getId(), 'unsupported_resource', 999);
    }

    public function testTicketListingFilterByResourceRelations(): void
    {
        $this->ticketService->createTicket([
            'user_id' => 10,
            'department_id' => $this->deptTech->getId(),
            'subject' => 'Service 100 ticket',
            'message' => 'Issue on 100',
            'service_id' => 100,
        ]);

        $this->ticketService->createTicket([
            'user_id' => 10,
            'department_id' => $this->deptTech->getId(),
            'subject' => 'Domain 200 ticket',
            'message' => 'Issue on domain 200',
            'domain_id' => 200,
        ]);

        $this->ticketService->createTicket([
            'user_id' => 10,
            'department_id' => $this->deptTech->getId(),
            'subject' => 'Invoice 300 ticket',
            'message' => 'Issue on invoice 300',
            'invoice_id' => 300,
        ]);

        $this->ticketService->createTicket([
            'user_id' => 10,
            'department_id' => $this->deptTech->getId(),
            'subject' => 'Order 400 ticket',
            'message' => 'Issue on order 400',
            'order_id' => 400,
        ]);

        $byService = $this->ticketService->listTickets(['service_id' => 100]);
        $this->assertCount(1, $byService);
        $this->assertSame('Service 100 ticket', $byService[0]->getSubject());

        $byDomain = $this->ticketService->listTickets(['domain_id' => 200]);
        $this->assertCount(1, $byDomain);

        $byInvoice = $this->ticketService->listTickets(['invoice_id' => 300]);
        $this->assertCount(1, $byInvoice);

        $byOrder = $this->ticketService->listTickets(['order_id' => 400]);
        $this->assertCount(1, $byOrder);
    }

    public function testContextSummaryAggregation(): void
    {
        // Populate mock context provider
        $this->contextProvider->setService(501, [
            'id' => 501,
            'package_name' => 'cPanel Cloud Pro',
            'server_name' => 'node-fra-01',
            'ip_address' => '192.168.1.10',
            'status' => 'active',
        ]);

        $this->contextProvider->setDomain(601, [
            'id' => 601,
            'domain_name' => 'colezademo.com',
            'registrar' => 'NameSilo',
            'status' => 'active',
            'expires_at' => '2027-10-09',
        ]);

        $this->contextProvider->setInvoice(701, [
            'id' => 701,
            'invoice_number' => 'INV-2026-0042',
            'total' => '$25.00',
            'status' => 'paid',
        ]);

        $ticket = $this->ticketService->createTicket([
            'user_id' => 10,
            'department_id' => $this->deptTech->getId(),
            'subject' => 'Performance question',
            'message' => 'Check server',
            'service_id' => 501,
            'domain_id' => 601,
            'invoice_id' => 701,
        ]);

        $summary = $this->contextService->getContextSummary($ticket->getId());

        $this->assertTrue($summary->hasService());
        $this->assertTrue($summary->hasDomain());
        $this->assertTrue($summary->hasInvoice());
        $this->assertFalse($summary->hasOrder());

        $this->assertSame('cPanel Cloud Pro', $summary->service['package_name']);
        $this->assertSame('colezademo.com', $summary->domain['domain_name']);
        $this->assertSame('INV-2026-0042', $summary->invoice['invoice_number']);

        $arr = $summary->toArray();
        $this->assertSame($ticket->getId(), $arr['ticket_id']);
        $this->assertIsArray($arr['service']);
    }

    public function testBuiltinContextualCommandsAndAuditLog(): void
    {
        $ticket = $this->ticketService->createTicket([
            'user_id' => 10,
            'department_id' => $this->deptTech->getId(),
            'subject' => 'Server restart request',
            'message' => 'Please reboot my VPS container',
            'service_id' => 888,
            'domain_id' => 999,
        ]);

        // 1. Service sync status
        $resService = $this->contextService->executeCommand(
            ticketId: $ticket->getId(),
            actorStaffUserId: 99,
            action: 'service.sync_status'
        );
        $this->assertTrue($resService->success);
        $this->assertStringContainsString('Service #888', $resService->message);

        // 2. Service reboot command
        $resReboot = $this->contextService->executeCommand(
            ticketId: $ticket->getId(),
            actorStaffUserId: 99,
            action: 'service.reboot'
        );
        $this->assertTrue($resReboot->success);

        // 3. Domain sync WHOIS
        $resDomain = $this->contextService->executeCommand(
            ticketId: $ticket->getId(),
            actorStaffUserId: 99,
            action: 'domain.sync_whois'
        );
        $this->assertTrue($resDomain->success);

        // 4. Action requiring invoice when no invoice linked -> FAILS gracefully
        $resInvoice = $this->contextService->executeCommand(
            ticketId: $ticket->getId(),
            actorStaffUserId: 99,
            action: 'invoice.resend_notification'
        );
        $this->assertFalse($resInvoice->success);
        $this->assertSame('No invoice linked to this ticket.', $resInvoice->message);

        // 5. Unsupported action -> FAILS gracefully
        $resUnknown = $this->contextService->executeCommand(
            ticketId: $ticket->getId(),
            actorStaffUserId: 99,
            action: 'custom.unknown_command'
        );
        $this->assertFalse($resUnknown->success);

        // Check command audit logs
        $logs = $this->contextService->getCommandLogs($ticket->getId());
        $this->assertCount(5, $logs);
        $this->assertSame('custom.unknown_command', $logs[0]['action']); // most recent first
        $this->assertSame(99, (int) $logs[0]['actor_staff_user_id']);
    }

    public function testCustomCommandHandlerExtension(): void
    {
        $ticket = $this->ticketService->createTicket([
            'user_id' => 10,
            'department_id' => $this->deptTech->getId(),
            'subject' => 'OS Reinstall',
            'message' => 'Please reinstall Ubuntu 24.04',
            'service_id' => 500,
        ]);

        // Register custom mock command handler
        $customHandler = new class implements TicketContextCommandHandlerInterface {
            public function supports(string $action): bool
            {
                return $action === 'vps.reinstall_os';
            }

            public function handle(string $action, int $ticketId, array $parameters = []): CommandExecutionResult
            {
                $os = $parameters['os'] ?? 'debian12';
                return CommandExecutionResult::successful($action, "Reinstall OS initiated with image '{$os}'", ['os' => $os]);
            }
        };

        $this->contextService->registerCommandHandler($customHandler);

        $result = $this->contextService->executeCommand(
            ticketId: $ticket->getId(),
            actorStaffUserId: 99,
            action: 'vps.reinstall_os',
            parameters: ['os' => 'ubuntu24']
        );

        $this->assertTrue($result->success);
        $this->assertSame("Reinstall OS initiated with image 'ubuntu24'", $result->message);
        $this->assertSame('ubuntu24', $result->data['os']);
    }
}
