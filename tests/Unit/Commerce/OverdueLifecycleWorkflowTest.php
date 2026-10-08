<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Commerce;

use Coleza\Domain\Commerce\Invoices\Invoice;
use Coleza\Domain\Commerce\Invoices\InvoiceService;
use Coleza\Domain\Commerce\Services\Lifecycle\OverdueGracePolicy;
use Coleza\Domain\Commerce\Services\Lifecycle\OverdueLifecycleWorkflow;
use Coleza\Domain\Commerce\Services\Service;
use Coleza\Domain\Commerce\Services\ServiceService;
use Coleza\Domain\Commerce\Services\ServiceStateMachine;
use Coleza\Domain\Notifications\NotificationEngine;
use Coleza\Domain\Notifications\Templates\NotificationTemplateEngine;
use Coleza\Domain\Notifications\Transport\MemoryMailTransport;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class OverdueLifecycleWorkflowTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private ServiceService $serviceService;
    private InvoiceService $invoiceService;
    private MemoryMailTransport $mailTransport;
    private NotificationEngine $notificationEngine;
    private OverdueLifecycleWorkflow $workflow;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($this->pdo, 'sqlite');

        $this->serviceService = new ServiceService($this->db);
        $this->serviceService->ensureTables();

        $this->invoiceService = new InvoiceService($this->db);
        $this->invoiceService->ensureTables();

        $this->mailTransport = new MemoryMailTransport();
        $templateEngine = new NotificationTemplateEngine();
        $this->notificationEngine = new NotificationEngine($this->mailTransport, $templateEngine);

        $this->workflow = new OverdueLifecycleWorkflow(
            serviceService: $this->serviceService,
            invoiceService: $this->invoiceService,
            notificationEngine: $this->notificationEngine
        );
    }

    public function testActiveServiceSuspendedWhenExceedingGracePeriod(): void
    {
        // Reference date: 2026-10-20
        $refDate = '2026-10-20';

        // Due date 2026-10-10 -> 10 days overdue, exceeding 7-day grace period
        $service = $this->createService(
            status: ServiceStateMachine::STATUS_ACTIVE,
            dueDate: '2026-10-10',
            email: 'client.grace@example.com'
        );

        $policy = new OverdueGracePolicy(gracePeriodDays: 7);
        $report = $this->workflow->evaluateAndProcessOverdue($policy, $refDate);

        $this->assertSame(1, $report->getSuspendedCount());
        $this->assertSame(0, $report->getFailedCount());

        // Assert service status in DB
        $updated = $this->serviceService->findServiceById($service->getId());
        $this->assertSame(ServiceStateMachine::STATUS_SUSPENDED, $updated->getStatus());
        $this->assertStringContainsString('Auto-suspended', (string)$updated->getSuspensionReason());

        // Assert suspension email sent
        $this->assertCount(1, $this->mailTransport->getSentMessages());
        $sent = $this->mailTransport->findLastByRecipient('client.grace@example.com');
        $this->assertNotNull($sent);
        $this->assertSame('client.grace@example.com', $sent->getRecipientEmail());
    }

    public function testActiveServiceReceivesWarningReminderInsideGracePeriod(): void
    {
        // Reference date: 2026-10-20
        $refDate = '2026-10-20';

        // Due date 2026-10-17 -> 3 days overdue (matches reminderDays [1, 3, 5], inside 7-day grace)
        $service = $this->createService(
            status: ServiceStateMachine::STATUS_ACTIVE,
            dueDate: '2026-10-17',
            email: 'reminder@example.com'
        );

        $policy = new OverdueGracePolicy(gracePeriodDays: 7, reminderDays: [1, 3, 5]);
        $report = $this->workflow->evaluateAndProcessOverdue($policy, $refDate);

        $this->assertSame(1, $report->getRemindersCount());
        $this->assertSame(0, $report->getSuspendedCount());

        // Service remains active
        $updated = $this->serviceService->findServiceById($service->getId());
        $this->assertSame(ServiceStateMachine::STATUS_ACTIVE, $updated->getStatus());

        // Warning email sent
        $this->assertCount(1, $this->mailTransport->getSentMessages());
        $sent = $this->mailTransport->findLastByRecipient('reminder@example.com');
        $this->assertNotNull($sent);
    }

    public function testSuspendedServiceTerminatedWhenExceedingTerminationGracePeriod(): void
    {
        // Reference date: 2026-10-20
        $refDate = '2026-10-20';

        // Due date 2026-09-10 -> 40 days overdue, exceeding 30-day termination threshold
        $service = $this->createService(
            status: ServiceStateMachine::STATUS_SUSPENDED,
            dueDate: '2026-09-10',
            email: 'terminated@example.com'
        );

        $policy = new OverdueGracePolicy(terminationGraceDays: 30);
        $report = $this->workflow->evaluateAndProcessOverdue($policy, $refDate);

        $this->assertSame(1, $report->getTerminatedCount());

        // Assert service terminated
        $updated = $this->serviceService->findServiceById($service->getId());
        $this->assertSame(ServiceStateMachine::STATUS_TERMINATED, $updated->getStatus());

        // Assert termination email sent
        $sent = $this->mailTransport->findLastByRecipient('terminated@example.com');
        $this->assertNotNull($sent);
    }

    public function testTerminationApprovalGateHold(): void
    {
        $refDate = '2026-10-20';
        $service = $this->createService(
            status: ServiceStateMachine::STATUS_SUSPENDED,
            dueDate: '2026-09-10'
        );

        $policy = new OverdueGracePolicy(
            terminationGraceDays: 30,
            requireApprovalForTermination: true
        );

        $report = $this->workflow->evaluateAndProcessOverdue($policy, $refDate);

        $this->assertSame(0, $report->getTerminatedCount());
        $results = $report->getResults();
        $this->assertSame('pending_approval', $results[0]->getAction());

        // Service stays suspended, not terminated
        $updated = $this->serviceService->findServiceById($service->getId());
        $this->assertSame(ServiceStateMachine::STATUS_SUSPENDED, $updated->getStatus());
    }

    public function testVipExemptionSkipsSuspension(): void
    {
        $refDate = '2026-10-20';

        // 15 days overdue but has VIP tag
        $service = $this->createService(
            status: ServiceStateMachine::STATUS_ACTIVE,
            dueDate: '2026-10-05',
            email: 'vip@corp.com',
            tags: ['vip', 'enterprise']
        );

        $policy = new OverdueGracePolicy(gracePeriodDays: 7, exemptTags: ['vip']);
        $report = $this->workflow->evaluateAndProcessOverdue($policy, $refDate);

        $this->assertSame(0, $report->getSuspendedCount());
        $results = $report->getResults();
        $this->assertSame('skipped_exempt', $results[0]->getAction());

        // Stays active
        $updated = $this->serviceService->findServiceById($service->getId());
        $this->assertSame(ServiceStateMachine::STATUS_ACTIVE, $updated->getStatus());
    }

    public function testAutomaticReactivationUponInvoiceSettlement(): void
    {
        // 1. Create a service that got suspended
        $service = $this->createService(
            status: ServiceStateMachine::STATUS_SUSPENDED,
            dueDate: '2026-10-01',
            email: 'reactivate@example.com'
        );
        $serviceId = $service->getId();

        // 2. Create invoice for this service
        $invoice = $this->invoiceService->createInvoice([
            'user_id' => 1,
            'status' => Invoice::STATUS_UNPAID,
            'currency_code' => 'USD',
            'subtotal_minor' => 3000,
            'tax_total_minor' => 0,
            'total_minor' => 3000,
        ], [
            [
                'description' => 'Renewal Invoice #1',
                'quantity' => 1,
                'unit_amount_minor' => 3000,
                'subtotal_minor' => 3000,
                'tax_amount_minor' => 0,
                'total_minor' => 3000,
                'service_id' => $serviceId,
            ],
        ]);

        $this->mailTransport->clear();

        // 3. Mark invoice as paid
        $this->invoiceService->applyPayment($invoice->getId(), 3000);

        // 4. Trigger payment unsuspend workflow
        $actions = $this->workflow->handleInvoicePaid($invoice->getId());

        $this->assertNotEmpty($actions);

        // Service must be active and due date advanced!
        $updated = $this->serviceService->findServiceById($serviceId);
        $this->assertSame(ServiceStateMachine::STATUS_ACTIVE, $updated->getStatus());
        // Next due date advanced from 2026-10-01 + 1 month = 2026-11-01
        $this->assertSame('2026-11-01', $updated->getNextDueDate());

        // Reactivation email sent to client
        $sent = $this->mailTransport->findLastByRecipient('reactivate@example.com');
        $this->assertNotNull($sent);
    }

    private function createService(
        string $status,
        string $dueDate,
        string $email = 'client@example.com',
        array $tags = []
    ): Service {
        return $this->serviceService->createService([
            'user_id' => 1,
            'organization_id' => 1,
            'product_id' => 10,
            'domain' => 'client-domain.com',
            'billing_cycle' => 'monthly',
            'recurring_amount_minor' => 3000,
            'currency_code' => 'USD',
            'status' => $status,
            'next_due_date' => $dueDate,
            'metadata' => [
                'customer_email' => $email,
                'tags' => $tags,
            ],
        ]);
    }
}
