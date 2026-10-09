<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Analytics;

use Coleza\Domain\Analytics\Financial\FinancialMetricsService;
use Coleza\Domain\Analytics\Reconciliation\LedgerReconciliationReport;
use Coleza\Domain\Analytics\Reconciliation\LedgerReconciliationService;
use Coleza\Domain\Analytics\Reconciliation\ReconciliationStatus;
use Coleza\Domain\Analytics\Testing\GoldenFinancialDataset;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class GoldenFinancialLedgerReconciliationTest extends TestCase
{
    private Connection $db;
    private LedgerReconciliationService $reconciliationService;
    private FinancialMetricsService $financialMetricsService;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->reconciliationService = new LedgerReconciliationService($this->db);
        $this->financialMetricsService = new FinancialMetricsService($this->db);
    }

    public function testGoldenDatasetSeedsCleanlyAndMatchesDeterministicExpectations(): void
    {
        GoldenFinancialDataset::seed($this->db);

        $summary = $this->financialMetricsService->calculatePeriodFinancialMetrics(
            startDate: GoldenFinancialDataset::DEFAULT_START_DATE,
            endDate: GoldenFinancialDataset::DEFAULT_END_DATE,
            currency: 'USD'
        );

        $this->assertSame(GoldenFinancialDataset::EXPECTED_GROSS_INVOICED, $summary->getGrossInvoiced());
        $this->assertSame(GoldenFinancialDataset::EXPECTED_NET_INVOICED, $summary->getNetInvoiced());
        $this->assertSame(GoldenFinancialDataset::EXPECTED_TAX_BILLED, $summary->getTaxBilled());
        $this->assertSame(GoldenFinancialDataset::EXPECTED_CASH_COLLECTED, $summary->getGrossCashCollected());
        $this->assertSame(GoldenFinancialDataset::EXPECTED_REFUNDS_ISSUED, $summary->getCashRefunded());
        $this->assertSame(GoldenFinancialDataset::EXPECTED_NET_CASH_FLOW, $summary->getNetCashFlow());
        $this->assertSame(3, $summary->getInvoiceCount());
        $this->assertSame(2, $summary->getPaidInvoiceCount());
        $this->assertSame(1, $summary->getUnpaidInvoiceCount());
    }

    public function testSourceLedgerReconciliationZeroUnexplainedDiff(): void
    {
        GoldenFinancialDataset::seed($this->db);

        $report = $this->reconciliationService->reconcile(
            startDate: GoldenFinancialDataset::DEFAULT_START_DATE,
            endDate: GoldenFinancialDataset::DEFAULT_END_DATE,
            currency: 'USD'
        );

        $this->assertInstanceOf(LedgerReconciliationReport::class, $report);
        $this->assertTrue($report->isReconciled());
        $this->assertSame(ReconciliationStatus::MATCHED, $report->getStatus());

        // Zero unexplained difference
        $this->assertSame(950.00, $report->getAnalyticsCashCollected());
        $this->assertSame(950.00, $report->getLedgerPaymentsCollected());
        $this->assertSame(0.0, $report->getPaymentVariance());

        $this->assertSame(100.00, $report->getAnalyticsCashRefunded());
        $this->assertSame(100.00, $report->getLedgerRefundsPaid());
        $this->assertSame(0.0, $report->getRefundVariance());

        $this->assertSame(850.00, $report->getAnalyticsNetCash());
        $this->assertSame(850.00, $report->getLedgerNetCash());
        $this->assertSame(0.0, $report->getNetCashVariance());

        $this->assertEmpty($report->getDiscrepancies());

        $array = $report->toArray();
        $this->assertTrue($array['is_reconciled']);
        $this->assertSame('matched', $array['status']);
    }

    public function testReconciliationWithDirectFinancialPeriodSummary(): void
    {
        GoldenFinancialDataset::seed($this->db);

        $summary = $this->financialMetricsService->calculatePeriodFinancialMetrics(
            startDate: '2026-10-01',
            endDate: '2026-10-31',
            currency: 'USD'
        );

        $report = $this->reconciliationService->reconcileFinancialPeriodSummary(
            summary: $summary,
            startDate: '2026-10-01',
            endDate: '2026-10-31',
            currency: 'USD'
        );

        $this->assertTrue($report->isReconciled());
        $this->assertSame(0.0, $report->getPaymentVariance());
        $this->assertSame(0.0, $report->getRefundVariance());
        $this->assertSame(0.0, $report->getNetCashVariance());
    }

    public function testReconciliationDetectsGhostLedgerTransactions(): void
    {
        GoldenFinancialDataset::seed($this->db);

        // Inject unrecorded credit in ledger ($25.00 / 2500 cents)
        GoldenFinancialDataset::injectLedgerDiscrepancy($this->db, 2500);

        $report = $this->reconciliationService->reconcile(
            startDate: GoldenFinancialDataset::DEFAULT_START_DATE,
            endDate: GoldenFinancialDataset::DEFAULT_END_DATE,
            currency: 'USD'
        );

        $this->assertFalse($report->isReconciled());
        $this->assertSame(ReconciliationStatus::DISCREPANCY_DETECTED, $report->getStatus());
        $this->assertSame(950.00, $report->getAnalyticsCashCollected());
        $this->assertSame(975.00, $report->getLedgerPaymentsCollected());
        $this->assertSame(-25.00, $report->getPaymentVariance());
        $this->assertSame(-25.00, $report->getNetCashVariance());

        $this->assertNotEmpty($report->getDiscrepancies());
        $this->assertStringContainsString('Payment variance detected', $report->getDiscrepancies()[0]);
    }

    public function testReconciliationDetectsAnalyticsRollupCorruption(): void
    {
        GoldenFinancialDataset::seed($this->db);

        // Inject corruption in read model (+$50.00)
        GoldenFinancialDataset::injectAnalyticsDiscrepancy($this->db, 50.0);

        $report = $this->reconciliationService->reconcile(
            startDate: GoldenFinancialDataset::DEFAULT_START_DATE,
            endDate: GoldenFinancialDataset::DEFAULT_END_DATE,
            currency: 'USD'
        );

        $this->assertFalse($report->isReconciled());
        $this->assertSame(ReconciliationStatus::DISCREPANCY_DETECTED, $report->getStatus());
        $this->assertSame(1000.00, $report->getAnalyticsCashCollected());
        $this->assertSame(950.00, $report->getLedgerPaymentsCollected());
        $this->assertSame(50.00, $report->getPaymentVariance());
        $this->assertSame(50.00, $report->getNetCashVariance());
    }

    public function testReconciliationValidatesDates(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Start date cannot be after end date');

        $this->reconciliationService->reconcile('2026-10-31', '2026-10-01');
    }
}
