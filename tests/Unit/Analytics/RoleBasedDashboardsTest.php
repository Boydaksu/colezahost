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
        \Coleza\Tests\Support\SubscriptionSourceFixture::install($this->db);

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
        \Coleza\Tests\Support\DailySourceFixture::install($this->db);
        $this->db->insert('support_departments', ['id' => 1, 'name' => 'billing']);
        $this->db->insert('support_departments', ['id' => 2, 'name' => 'technical']);
    }

    public function testOwnerDashboardAssembly(): void
    {
        \Coleza\Tests\Support\SubscriptionSourceFixture::service($this->db, 3000, 1);
        \Coleza\Tests\Support\SubscriptionSourceFixture::service($this->db, 2000, 2);
        // 2 active services ($30 and $20 = $50 MRR)

        // Payment captured
        $this->db->statement("INSERT INTO payments (status, created_at, amount_minor, currency_code, payment_number, user_id, payment_method, paid_at, net_amount_minor) VALUES ('completed', '2026-10-15 10:00:00', 10000, 'USD', 'APAY-13', 1, 'manual', '2026-10-15 10:00:00', 10000)");

        (new SubscriptionSnapshotService($this->db))->captureSnapshot('2026-10-31', 'USD');
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
        $this->db->statement("INSERT INTO invoices (user_id, status, due_date, created_at, total_minor, subtotal_minor, tax_total_minor, paid_amount_minor, currency_code, invoice_number, issue_date) VALUES (1, 'paid', '2026-10-10', '2026-10-05 00:00:00', 12000, 10000, 2000, 12000, 'USD', 'AINV-14', '2026-10-05')");
        $this->db->statement("INSERT INTO payments (status, created_at, amount_minor, fee_minor, currency_code, payment_number, user_id, payment_method, paid_at, net_amount_minor) VALUES ('completed', '2026-10-05 00:00:00', 12000, 360, 'USD', 'APAY-15', 1, 'manual', '2026-10-05 00:00:00', 11640)");

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
        $this->db->statement("INSERT INTO orders (created_at, total_minor, currency_code, order_number, user_id) VALUES ('2026-10-10 00:00:00', 8500, 'USD', 'AORD-16', 1)");
        $this->db->statement("INSERT INTO orders (created_at, total_minor, currency_code, order_number, user_id) VALUES ('2026-10-12 00:00:00', 11500, 'USD', 'AORD-17', 1)");
        $this->db->statement("INSERT INTO users (created_at, email, password_hash, name) VALUES ('2026-10-10 00:00:00', 'fixture18@example.test', 'fixture', 'Fixture')");
        \Coleza\Tests\Support\SubscriptionSourceFixture::service($this->db, 4500, 1);
        $this->db->insert('products', ['id' => 1, 'name' => 'cPanel Pro', 'slug' => 'cpanel-pro', 'group_id' => 1, 'type' => 'hosting']);

        $dashboard = $this->service->buildSalesDashboard('2026-10-01', '2026-10-31', 'USD');

        $this->assertSame(2, $dashboard->getNewOrdersCount());
        $this->assertSame(200.00, $dashboard->getNewOrdersVolume());
        $this->assertSame(1, $dashboard->getNewCustomersCount());
        $this->assertArrayHasKey('cPanel Pro', $dashboard->getTopSellingProducts());
    }

    public function testOperationsDashboardAssembly(): void
    {
        \Coleza\Tests\Support\SubscriptionSourceFixture::service($this->db, 1000, 1);
        \Coleza\Tests\Support\SubscriptionSourceFixture::service($this->db, 1000, 2);
        $this->db->statement("UPDATE services SET status = 'suspended' WHERE user_id = 2");
        $this->db->statement("INSERT INTO domains (status, expiry_date, user_id, domain, sld, tld) VALUES ('active', '2026-11-15', 1, 'fixture19.test', 'fixture', 'test')");
        $this->db->statement("INSERT INTO abuse_cases (status, case_number, category, severity, reporter_email, resource_type, resource_identifier, subject, description) VALUES ('investigating', 'ACASE-20', 'spam', 'medium', 'fixture@example.test', 'domain', 'fixture.test', 'Fixture', 'Fixture')");

        $dashboard = $this->service->buildOperationsDashboard('2026-10-31');

        $this->assertSame(1, $dashboard->getActiveHostingServices());
        $this->assertSame(1, $dashboard->getSuspendedServices());
        $this->assertSame(1, $dashboard->getDomainsUnderManagement());
        $this->assertSame(1, $dashboard->getDomainsExpiringNext30Days());
        $this->assertSame(1, $dashboard->getOpenAbuseCasesCount());
    }

    public function testSupportDashboardAssembly(): void
    {
        $this->db->statement("INSERT INTO support_tickets (department_id, priority, status, created_at, ticket_number, user_id, subject, updated_at) VALUES (1, 'urgent', 'resolved', '2026-10-05 00:00:00', 'ATICKET-21', 1, 'Fixture', '2026-10-05 00:00:00')");
        $this->db->statement("INSERT INTO support_tickets (department_id, priority, status, created_at, ticket_number, user_id, subject, updated_at) VALUES (2, 'normal', 'open', '2026-10-06 00:00:00', 'ATICKET-22', 1, 'Fixture', '2026-10-06 00:00:00')");

        $dashboard = $this->service->buildSupportDashboard('2026-10-01', '2026-10-31');

        $this->assertSame(2, $dashboard->getTicketsOpenedCount());
        $this->assertSame(1, $dashboard->getTicketsResolvedCount());
        $this->assertSame(1, $dashboard->getOpenTicketsBacklog());
        $this->assertNull($dashboard->getSlaComplianceRatePercent());
        $this->assertNull($dashboard->getFirstResponseTimeAvgMinutes());
        $this->assertArrayHasKey('billing', $dashboard->getDepartmentBreakdown());
        $this->assertArrayHasKey('technical', $dashboard->getDepartmentBreakdown());
    }
}
