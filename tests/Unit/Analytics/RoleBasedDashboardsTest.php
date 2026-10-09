<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Analytics;

use Coleza\Domain\Analytics\Dashboards\DashboardService;
use Coleza\Domain\Analytics\Dashboards\DashboardType;
use Coleza\Domain\Analytics\Financial\FinancialMetricsService;
use Coleza\Domain\Analytics\ReadModels\ReadModelAggregationService;
use Coleza\Domain\Analytics\Subscription\SubscriptionSnapshotService;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class RoleBasedDashboardsTest extends TestCase
{
    private Connection $db;
    private DashboardService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->createAllTables();

        $subService = new SubscriptionSnapshotService($this->db);
        $finService = new FinancialMetricsService($this->db);
        $readService = new ReadModelAggregationService($this->db);

        $subService->ensureTables();
        $readService->ensureTables();

        $this->service = new DashboardService(
            db: $this->db,
            subscriptionService: $subService,
            financialService: $finService,
            readModelService: $readService
        );
    }

    private function createAllTables(): void
    {
        $this->db->statement(
            'CREATE TABLE IF NOT EXISTS hosting_services (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INT NOT NULL,
                domain VARCHAR(255) NOT NULL,
                package_name VARCHAR(64) NOT NULL,
                billing_cycle VARCHAR(32) NOT NULL,
                recurring_amount DECIMAL(10,2) NOT NULL,
                currency VARCHAR(3) NOT NULL DEFAULT "USD",
                status VARCHAR(32) NOT NULL DEFAULT "active",
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $this->db->statement(
            'CREATE TABLE IF NOT EXISTS invoices (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INT NOT NULL,
                total_amount DECIMAL(10,2) NOT NULL,
                subtotal_amount DECIMAL(10,2) NOT NULL,
                tax_amount DECIMAL(10,2) NOT NULL,
                amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                status VARCHAR(32) NOT NULL,
                due_date VARCHAR(10) NOT NULL,
                currency VARCHAR(3) NOT NULL DEFAULT "USD",
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $this->db->statement(
            'CREATE TABLE IF NOT EXISTS payments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                amount DECIMAL(10,2) NOT NULL,
                fee_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                status VARCHAR(32) NOT NULL,
                currency VARCHAR(3) NOT NULL DEFAULT "USD",
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $this->db->statement(
            'CREATE TABLE IF NOT EXISTS refunds (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                amount DECIMAL(10,2) NOT NULL,
                status VARCHAR(32) NOT NULL,
                currency VARCHAR(3) NOT NULL DEFAULT "USD",
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $this->db->statement(
            'CREATE TABLE IF NOT EXISTS orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
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
            'CREATE TABLE IF NOT EXISTS domains (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                status VARCHAR(32) NOT NULL,
                expires_at VARCHAR(10) NOT NULL
            )'
        );

        $this->db->statement(
            'CREATE TABLE IF NOT EXISTS abuse_cases (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                status VARCHAR(32) NOT NULL
            )'
        );

        $this->db->statement(
            'CREATE TABLE IF NOT EXISTS support_tickets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                department_id VARCHAR(32) NOT NULL,
                priority VARCHAR(32) NOT NULL,
                status VARCHAR(32) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )'
        );
    }

    public function testOwnerDashboardAssembly(): void
    {
        // 2 active services ($30 and $20 = $50 MRR)
        $this->db->statement("INSERT INTO hosting_services (user_id, domain, package_name, billing_cycle, recurring_amount, status) VALUES (1, 'a.com', 'Basic', 'monthly', 30.00, 'active')");
        $this->db->statement("INSERT INTO hosting_services (user_id, domain, package_name, billing_cycle, recurring_amount, status) VALUES (2, 'b.com', 'Basic', 'monthly', 20.00, 'active')");

        // Payment captured
        $this->db->statement("INSERT INTO payments (amount, status, created_at) VALUES (100.00, 'captured', '2026-10-15 10:00:00')");

        $dashboard = $this->service->buildOwnerDashboard('2026-10-31', 'USD');

        $this->assertSame(50.00, $dashboard->getTotalMrr());
        $this->assertSame(600.00, $dashboard->getTotalArr());
        $this->assertSame(2, $dashboard->getActiveSubscriptions());
        $this->assertSame(2, $dashboard->getActiveCustomers());
        $this->assertSame(25.00, $dashboard->getArpu());
        $this->assertSame(100.00, $dashboard->getNetCashFlowLast30Days());

        $array = $dashboard->toArray();
        $this->assertSame(DashboardType::OWNER->value, $array['type']);
        $this->assertJson(json_encode($dashboard));
    }

    public function testFinanceDashboardAssembly(): void
    {
        $this->db->statement("INSERT INTO invoices (user_id, total_amount, subtotal_amount, tax_amount, amount_paid, status, due_date, created_at) VALUES (1, 120.00, 100.00, 20.00, 120.00, 'paid', '2026-10-10', '2026-10-05')");
        $this->db->statement("INSERT INTO payments (amount, fee_amount, status, created_at) VALUES (120.00, 3.60, 'captured', '2026-10-05')");

        $dashboard = $this->service->buildFinanceDashboard('2026-10-01', '2026-10-31', 'USD');

        $this->assertSame(120.00, $dashboard->getPeriodSummary()->getGrossInvoiced());
        $this->assertSame(120.00, $dashboard->getPeriodSummary()->getGrossCashCollected());
        $this->assertSame(20.00, $dashboard->getEstimatedVatLiability());
        $this->assertSame(3.60, $dashboard->getGatewayFeesTotal());

        $array = $dashboard->toArray();
        $this->assertSame(DashboardType::FINANCE->value, $array['type']);
    }

    public function testSalesDashboardAssembly(): void
    {
        $this->db->statement("INSERT INTO orders (total_amount, created_at) VALUES (85.00, '2026-10-10')");
        $this->db->statement("INSERT INTO orders (total_amount, created_at) VALUES (115.00, '2026-10-12')");
        $this->db->statement("INSERT INTO users (created_at) VALUES ('2026-10-10')");
        $this->db->statement("INSERT INTO hosting_services (user_id, domain, package_name, billing_cycle, recurring_amount, status) VALUES (1, 'c.com', 'cPanel Pro', 'monthly', 45.00, 'active')");

        $dashboard = $this->service->buildSalesDashboard('2026-10-01', '2026-10-31', 'USD');

        $this->assertSame(2, $dashboard->getNewOrdersCount());
        $this->assertSame(200.00, $dashboard->getNewOrdersVolume());
        $this->assertSame(1, $dashboard->getNewCustomersCount());
        $this->assertArrayHasKey('cPanel Pro', $dashboard->getTopSellingProducts());
    }

    public function testOperationsDashboardAssembly(): void
    {
        $this->db->statement("INSERT INTO hosting_services (user_id, domain, package_name, billing_cycle, recurring_amount, status) VALUES (1, 'x.com', 'A', 'monthly', 10, 'active')");
        $this->db->statement("INSERT INTO hosting_services (user_id, domain, package_name, billing_cycle, recurring_amount, status) VALUES (2, 'y.com', 'A', 'monthly', 10, 'suspended')");
        $this->db->statement("INSERT INTO domains (status, expires_at) VALUES ('active', '2026-11-15')");
        $this->db->statement("INSERT INTO abuse_cases (status) VALUES ('investigating')");

        $dashboard = $this->service->buildOperationsDashboard('2026-10-31');

        $this->assertSame(1, $dashboard->getActiveHostingServices());
        $this->assertSame(1, $dashboard->getSuspendedServices());
        $this->assertSame(1, $dashboard->getDomainsUnderManagement());
        $this->assertSame(1, $dashboard->getDomainsExpiringNext30Days());
        $this->assertSame(1, $dashboard->getOpenAbuseCasesCount());
    }

    public function testSupportDashboardAssembly(): void
    {
        $this->db->statement("INSERT INTO support_tickets (department_id, priority, status, created_at) VALUES ('billing', 'urgent', 'resolved', '2026-10-05')");
        $this->db->statement("INSERT INTO support_tickets (department_id, priority, status, created_at) VALUES ('technical', 'normal', 'open', '2026-10-06')");

        $dashboard = $this->service->buildSupportDashboard('2026-10-01', '2026-10-31');

        $this->assertSame(2, $dashboard->getTicketsOpenedCount());
        $this->assertSame(1, $dashboard->getTicketsResolvedCount());
        $this->assertSame(1, $dashboard->getOpenTicketsBacklog());
        $this->assertSame(50.00, $dashboard->getSlaComplianceRatePercent());
        $this->assertArrayHasKey('billing', $dashboard->getDepartmentBreakdown());
        $this->assertArrayHasKey('technical', $dashboard->getDepartmentBreakdown());
    }
}
