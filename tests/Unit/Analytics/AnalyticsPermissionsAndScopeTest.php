<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Analytics;

use Coleza\Domain\Analytics\Dashboards\DashboardType;
use Coleza\Domain\Analytics\Metrics\AggregationType;
use Coleza\Domain\Analytics\Metrics\MetricCategory;
use Coleza\Domain\Analytics\Metrics\MetricDataType;
use Coleza\Domain\Analytics\Metrics\MetricDefinition;
use Coleza\Domain\Analytics\Metrics\MetricRegistry;
use Coleza\Domain\Analytics\Metrics\TimeGrain;
use Coleza\Domain\Analytics\Security\AnalyticsDataScopeService;
use Coleza\Domain\Analytics\Security\AnalyticsPermissionService;
use Coleza\Domain\Analytics\Security\AnalyticsUserContext;
use Coleza\Foundation\Exceptions\AuthorizationException;
use PHPUnit\Framework\TestCase;

final class AnalyticsPermissionsAndScopeTest extends TestCase
{
    private AnalyticsPermissionService $permService;
    private AnalyticsDataScopeService $scopeService;
    private MetricRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new MetricRegistry();
        $this->permService = new AnalyticsPermissionService($this->registry);
        $this->scopeService = new AnalyticsDataScopeService();

        // Register sample definitions in registry
        $this->registry->register(new MetricDefinition(
            key: 'financial.net_cash_flow',
            name: 'Net Cash Flow',
            description: 'Cash inflows minus refunds',
            category: MetricCategory::CASH,
            dataType: MetricDataType::CURRENCY,
            aggregationType: AggregationType::SUM,
            sourceDomain: 'Finance',
            formula: 'sum(cash_collected) - sum(refunds)',
            grain: TimeGrain::DAILY
        ));

        $this->registry->register(new MetricDefinition(
            key: 'operations.hypervisor_load',
            name: 'Hypervisor Capacity Load',
            description: 'Average hypervisor CPU load',
            category: MetricCategory::OPERATIONS,
            dataType: MetricDataType::PERCENTAGE,
            aggregationType: AggregationType::AVERAGE,
            sourceDomain: 'Infrastructure',
            formula: 'avg(cpu_load_pct)',
            grain: TimeGrain::DAILY
        ));

        $this->registry->register(new MetricDefinition(
            key: 'support.ticket_backlog',
            name: 'Open Ticket Backlog',
            description: 'Unresolved support tickets count',
            category: MetricCategory::SUPPORT,
            dataType: MetricDataType::COUNT,
            aggregationType: AggregationType::COUNT,
            sourceDomain: 'Support',
            formula: 'count(open_tickets)',
            grain: TimeGrain::DAILY
        ));
    }

    public function testSuperAdminHasUniversalAccess(): void
    {
        $superAdmin = AnalyticsUserContext::forSuperAdmin(1);

        $this->assertTrue($this->permService->canViewDashboard(DashboardType::OWNER, $superAdmin));
        $this->assertTrue($this->permService->canViewDashboard(DashboardType::FINANCE, $superAdmin));
        $this->assertTrue($this->permService->canViewDashboard(DashboardType::SALES, $superAdmin));
        $this->assertTrue($this->permService->canViewDashboard(DashboardType::OPERATIONS, $superAdmin));
        $this->assertTrue($this->permService->canViewDashboard(DashboardType::SUPPORT, $superAdmin));

        $this->assertTrue($this->permService->canRebuildAnalytics($superAdmin));
        $this->assertTrue($this->permService->canExportAnalytics($superAdmin));
        $this->assertTrue($this->permService->canAccessMetric('financial.net_cash_flow', $superAdmin));

        // Enforce methods should not throw
        $this->permService->enforceDashboardAccess(DashboardType::OWNER, $superAdmin);
        $this->permService->enforceRebuildAccess($superAdmin);
        $this->permService->enforceExportAccess($superAdmin);
        $this->permService->enforceMetricAccess('financial.net_cash_flow', $superAdmin);

        // Scope filter
        $filter = $this->scopeService->buildOrganizationScopeFilter($superAdmin);
        $this->assertSame('1 = 1', $filter['sql']);
        $this->assertEmpty($filter['params']);
    }

    public function testStaffUserRoleSegregationAndEnforcement(): void
    {
        $financeStaff = AnalyticsUserContext::forStaff(2, ['analytics.view_financial']);

        // Can view Finance dashboard
        $this->assertTrue($this->permService->canViewDashboard(DashboardType::FINANCE, $financeStaff));

        // Cannot view Operations or Support dashboards
        $this->assertFalse($this->permService->canViewDashboard(DashboardType::OPERATIONS, $financeStaff));
        $this->assertFalse($this->permService->canViewDashboard(DashboardType::SUPPORT, $financeStaff));

        // Cannot rebuild without analytics.rebuild
        $this->assertFalse($this->permService->canRebuildAnalytics($financeStaff));

        // Enforce dashboard throws AuthorizationException
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Access denied: User does not have permission to view operations dashboard');
        $this->permService->enforceDashboardAccess(DashboardType::OPERATIONS, $financeStaff);
    }

    public function testEnforceRebuildAccessThrowsWhenMissingPermission(): void
    {
        $salesStaff = AnalyticsUserContext::forStaff(3, ['analytics.view_sales']);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('User does not have permission to trigger analytics rebuild');
        $this->permService->enforceRebuildAccess($salesStaff);
    }

    public function testEnforceExportAccessThrowsWhenMissingPermission(): void
    {
        $supportStaff = AnalyticsUserContext::forStaff(4, ['analytics.view_support']);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('User does not have permission to export analytics data');
        $this->permService->enforceExportAccess($supportStaff);
    }

    public function testMetricCategoryPermissionEnforcement(): void
    {
        $opsStaff = AnalyticsUserContext::forStaff(5, ['analytics.view_operations']);

        // Can access operational metric
        $this->assertTrue($this->permService->canAccessMetric('operations.hypervisor_load', $opsStaff));

        // Cannot access financial metric
        $this->assertFalse($this->permService->canAccessMetric('financial.net_cash_flow', $opsStaff));

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Access denied: User does not have permission to access metric [financial.net_cash_flow]');
        $this->permService->enforceMetricAccess('financial.net_cash_flow', $opsStaff);
    }

    public function testOrganizationDataScopeIsolationEnforced(): void
    {
        $orgUser = AnalyticsUserContext::forOrgUser(10, organizationId: 101, role: 'admin');

        // Access to own org succeeds
        $this->scopeService->enforceOrganizationAccess(101, $orgUser);
        $this->assertTrue(true); // Reached safely

        // Access to another org throws AuthorizationException
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Unauthorized access to organization [102] analytics data');
        $this->scopeService->enforceOrganizationAccess(102, $orgUser);
    }

    public function testScopeFilterSqlGeneration(): void
    {
        $orgUser = AnalyticsUserContext::forOrgUser(11, organizationId: 202);
        $filter = $this->scopeService->buildOrganizationScopeFilter($orgUser, 'org_id');

        $this->assertSame('org_id = :scoped_org_id', $filter['sql']);
        $this->assertSame(202, $filter['params']['scoped_org_id']);

        // Non-staff user with null organization
        $unscopedUser = new AnalyticsUserContext(userId: 99, isStaff: false, isSuperAdmin: false, organizationId: null);
        $unscopedFilter = $this->scopeService->buildOrganizationScopeFilter($unscopedUser);
        $this->assertSame('1 = 0', $unscopedFilter['sql']);
    }

    public function testSensitiveFinancialMetricRedaction(): void
    {
        $opsUser = AnalyticsUserContext::forStaff(12, ['analytics.view_operations']);
        $financeUser = AnalyticsUserContext::forStaff(13, ['analytics.view_financial']);

        $payload = [
            'total_mrr' => 15000.0,
            'active_services' => 350,
            'net_cash_flow' => 12400.0,
            'open_tickets' => 8,
        ];

        // Redacted for ops user
        $redacted = $this->scopeService->redactSensitiveMetrics($payload, $opsUser);
        $this->assertSame('[REDACTED]', $redacted['total_mrr']);
        $this->assertSame('[REDACTED]', $redacted['net_cash_flow']);
        $this->assertSame(350, $redacted['active_services']);
        $this->assertSame(8, $redacted['open_tickets']);

        // Preserved for finance user
        $clean = $this->scopeService->redactSensitiveMetrics($payload, $financeUser);
        $this->assertSame(15000.0, $clean['total_mrr']);
        $this->assertSame(12400.0, $clean['net_cash_flow']);
    }
}
