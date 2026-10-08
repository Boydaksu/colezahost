<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Commerce;

use Coleza\Domain\Commerce\Invoices\Invoice;
use Coleza\Domain\Commerce\Invoices\InvoiceService;
use Coleza\Domain\Commerce\Recurring\RenewalInvoiceService;
use Coleza\Domain\Commerce\Recurring\Scheduler\RenewalPolicy;
use Coleza\Domain\Commerce\Recurring\Scheduler\ServiceRenewalScheduler;
use Coleza\Domain\Commerce\Services\Service;
use Coleza\Domain\Commerce\Services\ServiceService;
use Coleza\Domain\Commerce\Services\ServiceStateMachine;
use Coleza\Domain\Notifications\NotificationEngine;
use Coleza\Domain\Notifications\Templates\NotificationTemplateEngine;
use Coleza\Domain\Notifications\Transport\MemoryMailTransport;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class ServiceRenewalSchedulerTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private ServiceService $serviceService;
    private InvoiceService $invoiceService;
    private RenewalInvoiceService $renewalInvoiceService;
    private MemoryMailTransport $mailTransport;
    private NotificationEngine $notificationEngine;
    private ServiceRenewalScheduler $scheduler;

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

        $this->scheduler = new ServiceRenewalScheduler(
            serviceService: $this->serviceService,
            renewalInvoiceService: $this->renewalInvoiceService,
            notificationEngine: $this->notificationEngine
        );
    }

    public function testRenewalBatchGeneratesInvoicesForDueServices(): void
    {
        // Reference date: 2026-10-15
        $refDate = '2026-10-15';

        // Service 1: due 2026-10-20 (5 days ahead, within 14 lead days)
        $s1 = $this->createService(dueDate: '2026-10-20', price: 1999, email: 'user1@example.com');
        // Service 2: due 2026-10-28 (13 days ahead, within 14 lead days)
        $s2 = $this->createService(dueDate: '2026-10-28', price: 4999, email: 'user2@example.com');
        // Service 3: due 2026-11-20 (36 days ahead, beyond 14 lead days)
        $s3 = $this->createService(dueDate: '2026-11-20', price: 9999, email: 'user3@example.com');

        $policy = new RenewalPolicy(leadDays: 14, autoRenewOnly: true, sendNotification: true);
        $report = $this->scheduler->run($policy, $refDate);

        $this->assertSame(2, $report->totalEvaluated());
        $this->assertSame(2, $report->getGeneratedCount());
        $this->assertSame(0, $report->getSkippedCount());
        $this->assertSame(0, $report->getFailedCount());

        $invoices = $report->getInvoices();
        $this->assertCount(2, $invoices);

        // Verify invoice totals
        $this->assertSame(1999, $invoices[0]->getTotalMinor());
        $this->assertSame(4999, $invoices[1]->getTotalMinor());

        // Verify customer notifications sent
        $this->assertCount(2, $this->mailTransport->getSentMessages());
        $this->assertNotNull($this->mailTransport->findLastByRecipient('user1@example.com'));
        $this->assertNotNull($this->mailTransport->findLastByRecipient('user2@example.com'));
    }

    public function testStrictIdempotencyPreventsDuplicateRenewalInvoices(): void
    {
        $refDate = '2026-10-15';
        $s1 = $this->createService(dueDate: '2026-10-20', price: 2500, email: 'idempotent@example.com');

        $policy = new RenewalPolicy(leadDays: 14);

        // First run: generates invoice
        $report1 = $this->scheduler->run($policy, $refDate);
        $this->assertSame(1, $report1->getGeneratedCount());
        $this->assertSame(0, $report1->getSkippedCount());

        $this->mailTransport->clear();

        // Second run on same day: must NOT generate duplicate invoice
        $report2 = $this->scheduler->run($policy, $refDate);
        $this->assertSame(0, $report2->getGeneratedCount());
        $this->assertSame(1, $report2->getSkippedCount());
        $this->assertStringContainsString('Renewal invoice already exists', (string)$report2->getResults()[0]->getSkippedReason());

        // No new email sent on second run
        $this->assertCount(0, $this->mailTransport->getSentMessages());
    }

    public function testAutoRenewPreferenceRespected(): void
    {
        $refDate = '2026-10-15';

        // Service with auto-renew disabled
        $s1 = $this->createService(dueDate: '2026-10-20', price: 3000, autoRenew: false);

        // Run with autoRenewOnly = true -> should skip
        $policyStrict = new RenewalPolicy(leadDays: 14, autoRenewOnly: true);
        $reportStrict = $this->scheduler->run($policyStrict, $refDate);

        // Since findServicesDueForRenewal filters by auto_renew = 1, it won't even evaluate or if evaluated it skips
        $this->assertSame(0, $reportStrict->getGeneratedCount());

        // Single service evaluation directly
        $singleResult = $this->scheduler->evaluateAndGenerateForService($s1, $policyStrict, $refDate);
        $this->assertTrue($singleResult->isSkipped());
        $this->assertSame('Auto-renew disabled for service', $singleResult->getSkippedReason());

        // Single service evaluation with autoRenewOnly = false -> generates invoice
        $policyPermissive = new RenewalPolicy(leadDays: 14, autoRenewOnly: false);
        $permissiveResult = $this->scheduler->evaluateAndGenerateForService($s1, $policyPermissive, $refDate);
        $this->assertTrue($permissiveResult->isGenerated());
        $this->assertSame(3000, $permissiveResult->getInvoice()->getTotalMinor());
    }

    public function testCustomInvoiceDueDatePolicy(): void
    {
        $refDate = '2026-10-15';
        $s = $this->createService(dueDate: '2026-10-25', price: 1500);

        // Policy specifies invoice is due 7 days from generation date
        $policy = new RenewalPolicy(leadDays: 14, autoRenewOnly: true, sendNotification: false, invoiceDueDays: 7);
        $report = $this->scheduler->run($policy, $refDate);

        $this->assertSame(1, $report->getGeneratedCount());
        $invoice = $report->getInvoices()[0];
        // 2026-10-15 + 7 days = 2026-10-22
        $this->assertSame('2026-10-22', $invoice->getDueDate());
    }

    private function createService(
        string $dueDate,
        int $price,
        string $email = 'client@example.com',
        bool $autoRenew = true
    ): Service {
        return $this->serviceService->createService([
            'user_id' => 1,
            'organization_id' => 1,
            'product_id' => 10,
            'domain' => 'hosting-test.com',
            'billing_cycle' => 'monthly',
            'recurring_amount_minor' => $price,
            'currency_code' => 'USD',
            'status' => ServiceStateMachine::STATUS_ACTIVE,
            'next_due_date' => $dueDate,
            'auto_renew' => $autoRenew,
            'metadata' => [
                'customer_email' => $email,
            ],
        ]);
    }
}
