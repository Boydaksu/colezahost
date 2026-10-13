<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Analytics;

use Coleza\Domain\Analytics\Financial\FinancialMetricsService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class InvoiceVsCashFinancialMetricsTest extends TestCase
{
    private Connection $db;
    private FinancialMetricsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->createFinancialTables();
        $this->service = new FinancialMetricsService($this->db);
    }

    private function createFinancialTables(): void
    {
        \Coleza\Tests\Support\DailySourceFixture::install($this->db);
    }

    public function testPeriodFinancialMetricsCalculatesInvoiceClaimsAndCashSeparately(): void
    {
        // 1. Invoices
        // Inv 1: $100 ($80 subtotal, $20 tax) - Paid
        $this->db->statement(
            "INSERT INTO invoices (user_id, status, due_date, created_at, total_minor, subtotal_minor, tax_total_minor, paid_amount_minor, currency_code, invoice_number, issue_date) VALUES (1, 'paid', '2026-10-15', '2026-10-05 10:00:00', 10000, 8000, 2000, 10000, 'USD', 'AINV-1', '2026-10-05')"
        );
        // Inv 2: $200 ($180 subtotal, $20 tax) - Unpaid
        $this->db->statement(
            "INSERT INTO invoices (user_id, status, due_date, created_at, total_minor, subtotal_minor, tax_total_minor, paid_amount_minor, currency_code, invoice_number, issue_date) VALUES (2, 'unpaid', '2026-10-20', '2026-10-06 12:00:00', 20000, 18000, 2000, 0, 'USD', 'AINV-2', '2026-10-06')"
        );
        // Inv 3: $50 - Cancelled (Must NOT be counted in invoiced gross)
        $this->db->statement(
            "INSERT INTO invoices (user_id, status, due_date, created_at, total_minor, subtotal_minor, tax_total_minor, paid_amount_minor, currency_code, invoice_number, issue_date) VALUES (3, 'cancelled', '2026-10-20', '2026-10-07 14:00:00', 5000, 5000, 0, 0, 'USD', 'AINV-3', '2026-10-07')"
        );

        // 2. Payments (Real Cash)
        $this->db->statement(
            "INSERT INTO payments (status, created_at, amount_minor, fee_minor, currency_code, payment_number, user_id, payment_method, paid_at, net_amount_minor) VALUES ('completed', '2026-10-05 10:05:00', 10000, 320, 'USD', 'APAY-4', 1, 'manual', '2026-10-05 10:05:00', 9680)"
        );

        // 3. Refunds
        $this->db->statement(
            "INSERT INTO refunds (amount_minor, currency_code, refund_number, payment_id, user_id, reason, refunded_at) VALUES (1500, 'USD', 'AREF-5', 1, 1, 'Fixture', '2026-10-10 15:00:00')"
        );

        $summary = $this->service->calculatePeriodFinancialMetrics('2026-10-01', '2026-10-31', 'USD');

        // Verify Invoiced Volume
        $this->assertSame(300.00, $summary->getGrossInvoiced());
        $this->assertSame(260.00, $summary->getNetInvoiced());
        $this->assertSame(40.00, $summary->getTaxBilled());
        $this->assertSame(2, $summary->getInvoiceCount());
        $this->assertSame(1, $summary->getPaidInvoiceCount());
        $this->assertSame(1, $summary->getUnpaidInvoiceCount());

        // Verify Cash Settlement
        $this->assertSame(100.00, $summary->getGrossCashCollected());
        $this->assertSame(15.00, $summary->getCashRefunded());
        $this->assertSame(85.00, $summary->getNetCashFlow());

        // Collection Efficiency = $100 cash / $300 invoiced = 33.33%
        $this->assertSame(33.33, $summary->getCollectionEfficiencyPercent());
    }

    public function testAgingReceivablesCategorizationAcrossBuckets(): void
    {
        // As of date: 2026-10-31
        // Inv 1: Due 2026-10-25 (6 days overdue) -> $50 unpaid -> current bucket
        $this->db->statement(
            "INSERT INTO invoices (user_id, status, due_date, total_minor, subtotal_minor, tax_total_minor, paid_amount_minor, currency_code, invoice_number, issue_date) VALUES (1, 'unpaid', '2026-10-25', 5000, 5000, 0, 0, 'USD', 'AINV-6', '2026-06-01')"
        );
        // Inv 2: Due 2026-09-20 (41 days overdue) -> $80 unpaid -> 31-60 days bucket
        $this->db->statement(
            "INSERT INTO invoices (user_id, status, due_date, total_minor, subtotal_minor, tax_total_minor, paid_amount_minor, currency_code, invoice_number, issue_date) VALUES (2, 'unpaid', '2026-09-20', 8000, 8000, 0, 0, 'USD', 'AINV-7', '2026-06-01')"
        );
        // Inv 3: Due 2026-08-10 (82 days overdue) -> $120 unpaid -> 61-90 days bucket
        $this->db->statement(
            "INSERT INTO invoices (user_id, status, due_date, total_minor, subtotal_minor, tax_total_minor, paid_amount_minor, currency_code, invoice_number, issue_date) VALUES (3, 'unpaid', '2026-08-10', 12000, 12000, 0, 0, 'USD', 'AINV-8', '2026-06-01')"
        );
        // Inv 4: Due 2026-06-01 (152 days overdue) -> $200 unpaid -> 90+ days bucket
        $this->db->statement(
            "INSERT INTO invoices (user_id, status, due_date, total_minor, subtotal_minor, tax_total_minor, paid_amount_minor, currency_code, invoice_number, issue_date) VALUES (4, 'unpaid', '2026-06-01', 20000, 20000, 0, 0, 'USD', 'AINV-9', '2026-06-01')"
        );
        // Inv 5: Fully paid -> must NOT appear in aging report
        $this->db->statement(
            "INSERT INTO invoices (user_id, status, due_date, total_minor, subtotal_minor, tax_total_minor, paid_amount_minor, currency_code, invoice_number, issue_date) VALUES (5, 'paid', '2026-10-01', 50000, 50000, 0, 50000, 'USD', 'AINV-10', '2026-06-01')"
        );

        $aging = $this->service->calculateAgingReceivables('2026-10-31', 'USD');

        $this->assertSame(450.00, $aging->getTotalOutstanding());
        $this->assertSame(50.00, $aging->getCurrentBucket());
        $this->assertSame(80.00, $aging->getThirtyToSixtyBucket());
        $this->assertSame(120.00, $aging->getSixtyToNinetyBucket());
        $this->assertSame(200.00, $aging->getOverNinetyBucket());
        $this->assertSame(4, $aging->getOverdueInvoicesCount());
    }

    public function testContributionMarginCalculatesNetAndMarginPercent(): void
    {
        // $1000 cash collected, $30 gateway fees
        $this->db->statement(
            "INSERT INTO payments (status, created_at, amount_minor, fee_minor, currency_code, payment_number, user_id, payment_method, paid_at, net_amount_minor) VALUES ('completed', '2026-10-02 10:00:00', 100000, 3000, 'USD', 'APAY-11', 1, 'manual', '2026-10-02 10:00:00', 97000)"
        );
        // $100 refunded
        $this->db->statement(
            "INSERT INTO refunds (amount_minor, currency_code, refund_number, payment_id, user_id, reason, refunded_at) VALUES (10000, 'USD', 'AREF-12', 1, 1, 'Fixture', '2026-10-05 10:00:00')"
        );

        // Direct costs: $200 (servers, licenses)
        $margin = $this->service->calculateContributionMargin(
            startDate: '2026-10-01',
            endDate: '2026-10-31',
            currency: 'USD',
            directCostsTotal: 200.00
        );

        $this->assertSame(1000.00, $margin->getGrossCashCollected());
        $this->assertSame(100.00, $margin->getCashRefunded());
        $this->assertSame(900.00, $margin->getNetCashFlow());
        $this->assertSame(30.00, $margin->getGatewayFeesTotal());
        $this->assertSame(200.00, $margin->getDirectCostsTotal());

        // Net Contribution = $900 net cash - $30 gateway fees - $200 direct costs = $670
        $this->assertSame(670.00, $margin->getNetContribution());
        // Margin = ($670 / $900) * 100 = 74.44%
        $this->assertSame(74.44, $margin->getContributionMarginPercent());
    }

    public function testDateValidationThrowsOnInvalidInputs(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Start date must follow YYYY-MM-DD format.');
        $this->service->calculatePeriodFinancialMetrics('bad-date', '2026-10-31');
    }

    public function testDateValidationThrowsWhenStartDateAfterEndDate(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Start date cannot be after end date.');
        $this->service->calculatePeriodFinancialMetrics('2026-11-01', '2026-10-01');
    }
}
