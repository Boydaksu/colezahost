<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Commerce;

use Coleza\Domain\Commerce\Invoices\InvoiceService;
use Coleza\Domain\Commerce\Recurring\RenewalInvoiceService;
use Coleza\Domain\Commerce\Recurring\Scheduler\MissedSchedulerCatchupService;
use Coleza\Domain\Commerce\Recurring\Scheduler\RenewalPolicy;
use Coleza\Domain\Commerce\Recurring\Scheduler\ServiceRenewalScheduler;
use Coleza\Domain\Commerce\Services\Lifecycle\OverdueGracePolicy;
use Coleza\Domain\Commerce\Services\Lifecycle\OverdueLifecycleWorkflow;
use Coleza\Domain\Commerce\Services\ServicePlacement;
use Coleza\Domain\Commerce\Services\ServiceService;
use Coleza\Domain\Commerce\Services\ServiceStateMachine;
use Coleza\Domain\Notifications\NotificationEngine;
use Coleza\Domain\Notifications\Templates\NotificationTemplateEngine;
use Coleza\Domain\Notifications\Transport\MemoryMailTransport;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class RenewalGoldenE2ETest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private ServiceService $serviceService;
    private InvoiceService $invoiceService;
    private RenewalInvoiceService $renewalInvoiceService;
    private ServiceRenewalScheduler $renewalScheduler;
    private OverdueLifecycleWorkflow $overdueWorkflow;
    private MissedSchedulerCatchupService $catchupService;
    private MemoryMailTransport $mailTransport;
    private NotificationEngine $notificationEngine;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($this->pdo, 'sqlite');

        $this->serviceService = new ServiceService($this->db);
        $this->serviceService->ensureTables();

        $this->invoiceService = new InvoiceService($this->db);
        $this->invoiceService->ensureTables();

        $this->renewalInvoiceService = new RenewalInvoiceService($this->db, $this->invoiceService);
        $this->renewalInvoiceService->ensureTables();

        $this->mailTransport = new MemoryMailTransport();
        $templateEngine = new NotificationTemplateEngine();
        $this->notificationEngine = new NotificationEngine($this->mailTransport, $templateEngine);

        $this->renewalScheduler = new ServiceRenewalScheduler(
            serviceService: $this->serviceService,
            renewalInvoiceService: $this->renewalInvoiceService,
            notificationEngine: $this->notificationEngine
        );

        $this->overdueWorkflow = new OverdueLifecycleWorkflow(
            serviceService: $this->serviceService,
            invoiceService: $this->invoiceService,
            notificationEngine: $this->notificationEngine
        );

        $this->catchupService = new MissedSchedulerCatchupService(
            db: $this->db,
            renewalScheduler: $this->renewalScheduler,
            overdueWorkflow: $this->overdueWorkflow
        );
        $this->catchupService->ensureTables();
    }

    public function testRenewalGoldenLifecycleEndToEnd(): void
    {
        $renewalPolicy = new RenewalPolicy(leadDays: 14, invoiceDueDays: 14);
        $gracePolicy = new OverdueGracePolicy(
            gracePeriodDays: 7,
            reminderDays: [1, 3, 5],
            terminationGraceDays: 30
        );

        // =========================================================================
        // STEP 1: Provision active service with recurring monthly cycle
        // Next due date: 2026-11-01
        // =========================================================================
        $service = $this->serviceService->createService([
            'user_id' => 42,
            'organization_id' => 1,
            'product_id' => 101,
            'domain' => 'client-golden.com',
            'billing_cycle' => 'monthly',
            'recurring_amount_minor' => 4500, // $45.00
            'currency_code' => 'USD',
            'status' => ServiceStateMachine::STATUS_ACTIVE,
            'next_due_date' => '2026-11-01',
            'auto_renew' => 1,
            'metadata' => [
                'customer_email' => 'founder@client-golden.com',
                'plan_name' => 'Pro Cloud VPS',
            ],
        ]);
        $serviceId = (int) $service->getId();

        $this->assertSame(ServiceStateMachine::STATUS_ACTIVE, $service->getStatus());
        $this->assertSame('2026-11-01', $service->getNextDueDate());

        // =========================================================================
        // STEP 2: Advance time to 2026-10-18 (14 days lead time) -> Trigger Renewal
        // =========================================================================
        $batch1 = $this->renewalScheduler->run($renewalPolicy, '2026-10-18');
        $this->assertSame(1, $batch1->getGeneratedCount());
        $this->assertSame(0, $batch1->getFailedCount());

        $genResult = $batch1->getResults()[0];
        $this->assertTrue($genResult->isGenerated());
        $invoice = $genResult->getInvoice();
        $this->assertNotNull($invoice);
        $this->assertSame(4500, $invoice->getTotalMinor());
        $this->assertSame('2026-11-01', $invoice->getDueDate());

        // Verify idempotency: re-running on same or next day before payment creates no duplicate
        $batchIdempotent = $this->renewalScheduler->run($renewalPolicy, '2026-10-19');
        $this->assertSame(0, $batchIdempotent->getGeneratedCount());
        $this->assertSame(1, $batchIdempotent->getSkippedCount());

        // Verify customer received renewal invoice notification
        $sentEmail = $this->mailTransport->findLastByRecipient('founder@client-golden.com');
        $this->assertNotNull($sentEmail);
        $this->assertStringContainsString($invoice->getInvoiceNumber(), $sentEmail->getHtmlBody());

        // =========================================================================
        // STEP 3: Advance time to 2026-11-04 (3 days overdue) -> Warning reminder
        // =========================================================================
        $this->mailTransport->clear();
        $overdueReport1 = $this->overdueWorkflow->evaluateAndProcessOverdue($gracePolicy, '2026-11-04');
        $this->assertSame(1, $overdueReport1->getRemindersCount());
        $this->assertSame(0, $overdueReport1->getSuspendedCount());

        // Service must still be ACTIVE
        $svcCheck1 = $this->serviceService->findServiceById($serviceId);
        $this->assertSame(ServiceStateMachine::STATUS_ACTIVE, $svcCheck1->getStatus());

        // Reminder email sent
        $reminderEmail = $this->mailTransport->findLastByRecipient('founder@client-golden.com');
        $this->assertNotNull($reminderEmail);
        $this->assertStringContainsString('3 days past due', $reminderEmail->getHtmlBody());

        // =========================================================================
        // STEP 4: Advance time to 2026-11-08 (7 days overdue) -> Auto-suspend
        // =========================================================================
        $this->mailTransport->clear();
        $overdueReport2 = $this->overdueWorkflow->evaluateAndProcessOverdue($gracePolicy, '2026-11-08');
        $this->assertSame(1, $overdueReport2->getSuspendedCount());

        $svcSuspended = $this->serviceService->findServiceById($serviceId);
        $this->assertSame(ServiceStateMachine::STATUS_SUSPENDED, $svcSuspended->getStatus());
        $this->assertStringContainsString('Auto-suspended', (string) $svcSuspended->getSuspensionReason());

        // Suspension email sent
        $suspensionEmail = $this->mailTransport->findLastByRecipient('founder@client-golden.com');
        $this->assertNotNull($suspensionEmail);
        $this->assertStringContainsString('Service Suspended', $suspensionEmail->getSubject());

        // =========================================================================
        // STEP 5: Payment settlement -> Auto-reactivate and cycle advance
        // =========================================================================
        $this->mailTransport->clear();
        $this->invoiceService->applyPayment($invoice->getId(), 4500);

        $actions = $this->overdueWorkflow->handleInvoicePaid($invoice->getId(), $gracePolicy);
        $this->assertCount(2, $actions); // unsuspended + renewed

        $svcReactivated = $this->serviceService->findServiceById($serviceId);
        $this->assertSame(ServiceStateMachine::STATUS_ACTIVE, $svcReactivated->getStatus());
        $this->assertSame('2026-12-01', $svcReactivated->getNextDueDate()); // advanced by 1 month!

        // Unsuspended email sent
        $reactivationEmail = $this->mailTransport->findLastByRecipient('founder@client-golden.com');
        $this->assertNotNull($reactivationEmail);
        $this->assertStringContainsString('Service Reactivated', $reactivationEmail->getSubject());

        // =========================================================================
        // STEP 6: Missed Scheduler Catchup
        // Simulate scheduler outage between 2026-11-15 and 2026-11-20 (5 days).
        // 2026-11-17 is 14 days before 2026-12-01 (due date), so it should generate the next cycle renewal!
        // =========================================================================
        $this->catchupService->recordCheckpoint('daily_cron', '2026-11-15');

        $catchupReport = $this->catchupService->catchup(
            schedulerName: 'daily_cron',
            currentDate: '2026-11-20',
            renewalPolicy: $renewalPolicy,
            gracePolicy: $gracePolicy
        );

        $this->assertSame(5, $catchupReport->getMissedDaysCount());
        $this->assertSame(1, $catchupReport->getTotalInvoicesGenerated());
        $this->assertSame('2026-11-20', $this->catchupService->getLastCheckpoint('daily_cron'));

        // Check renewal record for second period
        $renewalRecord = $this->renewalInvoiceService->findRenewalRecord($serviceId, '2026-12-01');
        $this->assertNotNull($renewalRecord);

        // =========================================================================
        // STEP 7: Permanent Termination Workflow for abandoned service
        // =========================================================================
        $abandonedService = $this->serviceService->createService([
            'user_id' => 99,
            'organization_id' => 1,
            'product_id' => 101,
            'domain' => 'abandoned-client.com',
            'billing_cycle' => 'monthly',
            'recurring_amount_minor' => 2000,
            'currency_code' => 'USD',
            'status' => ServiceStateMachine::STATUS_SUSPENDED,
            'next_due_date' => '2026-10-01', // 50 days overdue relative to 2026-11-20
            'auto_renew' => 0,
            'metadata' => ['customer_email' => 'client@abandoned.com'],
        ]);
        $abandonedId = (int) $abandonedService->getId();

        $this->mailTransport->clear();
        $termReport = $this->overdueWorkflow->evaluateAndProcessOverdue($gracePolicy, '2026-11-20');
        $this->assertSame(1, $termReport->getTerminatedCount());

        $svcTerminated = $this->serviceService->findServiceById($abandonedId);
        $this->assertSame(ServiceStateMachine::STATUS_TERMINATED, $svcTerminated->getStatus());
        $this->assertNotNull($svcTerminated->getTerminationDate());

        $termEmail = $this->mailTransport->findLastByRecipient('client@abandoned.com');
        $this->assertNotNull($termEmail);
        $this->assertStringContainsString('Service Terminated', $termEmail->getSubject());
    }
}
