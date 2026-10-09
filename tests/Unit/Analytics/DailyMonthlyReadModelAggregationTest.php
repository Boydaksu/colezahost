<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Analytics;

use Coleza\Domain\Analytics\ReadModels\ReadModelAggregationService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class DailyMonthlyReadModelAggregationTest extends TestCase
{
    private Connection $db;
    private ReadModelAggregationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->createSourceTables();
        $this->service = new ReadModelAggregationService($this->db);
        $this->service->ensureTables();
    }

    private function createSourceTables(): void
    {
        $this->db->statement(
            'CREATE TABLE IF NOT EXISTS invoices (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                total_amount DECIMAL(10,2) NOT NULL,
                subtotal_amount DECIMAL(10,2) NOT NULL,
                tax_amount DECIMAL(10,2) NOT NULL,
                status VARCHAR(32) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $this->db->statement(
            'CREATE TABLE IF NOT EXISTS payments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                amount DECIMAL(10,2) NOT NULL,
                fee_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                status VARCHAR(32) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $this->db->statement(
            'CREATE TABLE IF NOT EXISTS refunds (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                amount DECIMAL(10,2) NOT NULL,
                status VARCHAR(32) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $this->db->statement(
            'CREATE TABLE IF NOT EXISTS orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $this->db->statement(
            'CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $this->db->statement(
            'CREATE TABLE IF NOT EXISTS support_tickets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                status VARCHAR(32) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $this->db->statement(
            'CREATE TABLE IF NOT EXISTS analytics_subscription_snapshots (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                snapshot_date VARCHAR(10) NOT NULL,
                currency VARCHAR(3) NOT NULL,
                total_mrr DECIMAL(10,2) NOT NULL,
                total_arr DECIMAL(10,2) NOT NULL,
                new_mrr DECIMAL(10,2) NOT NULL,
                churned_mrr DECIMAL(10,2) NOT NULL
            )'
        );
    }

    public function testDailyAggregationComputationAndStorage(): void
    {
        $targetDate = '2026-10-15';

        // 1. Invoices
        $this->db->statement(
            "INSERT INTO invoices (total_amount, subtotal_amount, tax_amount, status, created_at)
             VALUES (150.00, 125.00, 25.00, 'paid', '2026-10-15 10:00:00')"
        );
        $this->db->statement(
            "INSERT INTO invoices (total_amount, subtotal_amount, tax_amount, status, created_at)
             VALUES (200.00, 180.00, 20.00, 'unpaid', '2026-10-15 14:00:00')"
        );

        // 2. Payments
        $this->db->statement(
            "INSERT INTO payments (amount, fee_amount, status, created_at)
             VALUES (150.00, 4.50, 'captured', '2026-10-15 10:05:00')"
        );

        // 3. Refunds
        $this->db->statement(
            "INSERT INTO refunds (amount, status, created_at)
             VALUES (20.00, 'completed', '2026-10-15 16:00:00')"
        );

        // 4. Orders & Customers
        $this->db->statement("INSERT INTO orders (created_at) VALUES ('2026-10-15 09:00:00')");
        $this->db->statement("INSERT INTO orders (created_at) VALUES ('2026-10-15 11:00:00')");
        $this->db->statement("INSERT INTO users (created_at) VALUES ('2026-10-15 09:00:00')");

        // 5. Support tickets
        $this->db->statement("INSERT INTO support_tickets (status, created_at, updated_at) VALUES ('resolved', '2026-10-15 08:00:00', '2026-10-15 12:00:00')");

        $daily = $this->service->aggregateDay($targetDate, 'USD');

        $this->assertSame($targetDate, $daily->getDate());
        $this->assertSame('USD', $daily->getCurrency());
        $this->assertSame(350.00, $daily->getGrossInvoiced());
        $this->assertSame(305.00, $daily->getNetInvoiced());
        $this->assertSame(45.00, $daily->getTaxBilled());
        $this->assertSame(2, $daily->getInvoicesCount());
        $this->assertSame(150.00, $daily->getCashCollected());
        $this->assertSame(20.00, $daily->getCashRefunded());
        $this->assertSame(130.00, $daily->getNetCashFlow());
        $this->assertSame(4.50, $daily->getGatewayFees());
        $this->assertSame(2, $daily->getNewOrdersCount());
        $this->assertSame(1, $daily->getNewCustomersCount());
        $this->assertSame(1, $daily->getTicketsOpenedCount());
        $this->assertSame(1, $daily->getTicketsResolvedCount());
    }

    public function testRebuildDailyDateRangeBackfill(): void
    {
        $startDate = '2026-10-01';
        $endDate = '2026-10-05';

        $report = $this->service->rebuildDateRange($startDate, $endDate, 'USD');

        $this->assertSame('daily', $report->getScope());
        $this->assertSame(5, $report->getItemsRebuiltCount());
        $this->assertSame('USD', $report->getCurrency());
        $this->assertNotEmpty($report->getRebuildChecksum());

        $series = $this->service->getDailySeries($startDate, $endDate, 'USD');
        $this->assertCount(5, $series);
        $this->assertSame('2026-10-01', $series[0]->getDate());
        $this->assertSame('2026-10-05', $series[4]->getDate());
    }

    public function testMonthlyAggregationDerivationFromDailyAndSnapshots(): void
    {
        // Populate pre-aggregated daily rows
        $this->db->statement(
            "INSERT INTO analytics_daily_aggregations (date, currency, gross_invoiced, net_invoiced, tax_billed, cash_collected, cash_refunded, net_cash_flow, gateway_fees)
             VALUES ('2026-10-01', 'USD', 100.00, 80.00, 20.00, 100.00, 0.00, 100.00, 3.00)"
        );
        $this->db->statement(
            "INSERT INTO analytics_daily_aggregations (date, currency, gross_invoiced, net_invoiced, tax_billed, cash_collected, cash_refunded, net_cash_flow, gateway_fees)
             VALUES ('2026-10-02', 'USD', 200.00, 180.00, 20.00, 150.00, 10.00, 140.00, 4.50)"
        );

        // Subscription snapshot for end of month
        $this->db->statement(
            "INSERT INTO analytics_subscription_snapshots (snapshot_date, currency, total_mrr, total_arr, new_mrr, churned_mrr)
             VALUES ('2026-10-31', 'USD', 2500.00, 30000.00, 300.00, 50.00)"
        );

        $monthly = $this->service->aggregateMonth('2026-10', 'USD');

        $this->assertSame('2026-10', $monthly->getYearMonth());
        $this->assertSame(300.00, $monthly->getGrossInvoiced());
        $this->assertSame(260.00, $monthly->getNetInvoiced());
        $this->assertSame(40.00, $monthly->getTaxBilled());
        $this->assertSame(250.00, $monthly->getCashCollected());
        $this->assertSame(10.00, $monthly->getCashRefunded());
        $this->assertSame(240.00, $monthly->getNetCashFlow());
        $this->assertSame(7.50, $monthly->getGatewayFees());
        $this->assertSame(2500.00, $monthly->getEndOfMonthMrr());
        $this->assertSame(30000.00, $monthly->getEndOfMonthArr());
    }

    public function testRebuildIdempotencyDoesNotDuplicateRows(): void
    {
        $date = '2026-10-20';
        $this->service->aggregateDay($date, 'USD');
        $this->service->aggregateDay($date, 'USD');

        $rows = $this->db->select(
            "SELECT count(*) as cnt FROM analytics_daily_aggregations WHERE date = :d AND currency = 'USD'",
            ['d' => $date]
        );
        $this->assertSame(1, (int) $rows[0]['cnt']);
    }

    public function testDateValidationRules(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Date must follow YYYY-MM-DD format.');
        $this->service->aggregateDay('2026/10/20', 'USD');
    }
}
