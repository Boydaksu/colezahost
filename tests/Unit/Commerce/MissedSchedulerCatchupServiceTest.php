<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Commerce;

use Coleza\Domain\Commerce\Invoices\InvoiceService;
use Coleza\Domain\Commerce\Recurring\RenewalInvoiceService;
use Coleza\Domain\Commerce\Recurring\Scheduler\CatchupBatchReport;
use Coleza\Domain\Commerce\Recurring\Scheduler\MissedSchedulerCatchupService;
use Coleza\Domain\Commerce\Recurring\Scheduler\RenewalPolicy;
use Coleza\Domain\Commerce\Recurring\Scheduler\ServiceRenewalScheduler;
use Coleza\Domain\Commerce\Services\Lifecycle\OverdueGracePolicy;
use Coleza\Domain\Commerce\Services\Lifecycle\OverdueLifecycleWorkflow;
use Coleza\Domain\Commerce\Services\ServiceService;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class MissedSchedulerCatchupServiceTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private ServiceService $serviceService;
    private InvoiceService $invoiceService;
    private RenewalInvoiceService $renewalInvoiceService;
    private ServiceRenewalScheduler $renewalScheduler;
    private OverdueLifecycleWorkflow $overdueWorkflow;
    private MissedSchedulerCatchupService $catchupService;

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

        $this->renewalScheduler = new ServiceRenewalScheduler(
            serviceService: $this->serviceService,
            renewalInvoiceService: $this->renewalInvoiceService
        );

        $this->overdueWorkflow = new OverdueLifecycleWorkflow(
            serviceService: $this->serviceService,
            invoiceService: $this->invoiceService
        );

        $this->catchupService = new MissedSchedulerCatchupService(
            db: $this->db,
            renewalScheduler: $this->renewalScheduler,
            overdueWorkflow: $this->overdueWorkflow
        );
        $this->catchupService->ensureTables();
    }

    public function testComputeMissingDatesWithLimits(): void
    {
        // 1. Normal gap: 3 missing days
        $dates = $this->catchupService->computeMissingDates('2026-10-01', '2026-10-04');
        $this->assertSame(['2026-10-02', '2026-10-03', '2026-10-04'], $dates);

        // 2. Same date
        $datesSame = $this->catchupService->computeMissingDates('2026-10-04', '2026-10-04');
        $this->assertSame(['2026-10-04'], $datesSame);

        // 3. Current earlier than last (clock skew)
        $datesPast = $this->catchupService->computeMissingDates('2026-10-05', '2026-10-04');
        $this->assertSame(['2026-10-04'], $datesPast);

        // 4. Max days limit cap: 10 day gap capped at 3
        $datesCapped = $this->catchupService->computeMissingDates('2026-10-01', '2026-10-10', 3);
        $this->assertCount(3, $datesCapped);
        $this->assertSame(['2026-10-02', '2026-10-03', '2026-10-04'], $datesCapped);
    }

    public function testCheckpointsPersistence(): void
    {
        $this->assertNull($this->catchupService->getLastCheckpoint('cron_test'));

        $this->catchupService->recordCheckpoint('cron_test', '2026-10-01');
        $this->assertSame('2026-10-01', $this->catchupService->getLastCheckpoint('cron_test'));

        // Update checkpoint
        $this->catchupService->recordCheckpoint('cron_test', '2026-10-02');
        $this->assertSame('2026-10-02', $this->catchupService->getLastCheckpoint('cron_test'));
    }

    public function testFirstRunInitializesCheckpoint(): void
    {
        $renewalPolicy = new RenewalPolicy();
        $gracePolicy = new OverdueGracePolicy();

        $report = $this->catchupService->catchup(
            schedulerName: 'first_cron',
            currentDate: '2026-10-05',
            renewalPolicy: $renewalPolicy,
            gracePolicy: $gracePolicy
        );

        $this->assertSame('first_cron', $report->getSchedulerName());
        $this->assertSame('2026-10-05', $report->getStartDate());
        $this->assertSame('2026-10-05', $report->getEndDate());
        $this->assertSame(0, $report->getMissedDaysCount());
        $this->assertSame('2026-10-05', $this->catchupService->getLastCheckpoint('first_cron'));

        $arr = $report->toArray();
        $this->assertSame('first_cron', $arr['scheduler_name']);
        $this->assertArrayHasKey('renewal_reports', $arr);
        $this->assertArrayHasKey('overdue_reports', $arr);
    }
}
