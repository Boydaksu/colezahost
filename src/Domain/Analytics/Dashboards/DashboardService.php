<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Dashboards;

use Coleza\Domain\Analytics\Financial\FinancialMetricsService;
use Coleza\Domain\Analytics\ReadModels\ReadModelAggregationService;
use Coleza\Domain\Analytics\Subscription\SubscriptionSnapshotService;
use Coleza\Foundation\Database\Connection;
use DateTimeImmutable;
use Coleza\Domain\Analytics\AnalyticsInput;
use Coleza\Domain\Analytics\Subscription\MrrCalculator;
use Coleza\Domain\Analytics\Subscription\SubscriptionSnapshot;
use Coleza\Domain\Pricing\Entities\PriceCycle;
use LogicException;

final class DashboardService
{
    public function __construct(
        private readonly Connection $db,
        private readonly SubscriptionSnapshotService $subscriptionService,
        private readonly FinancialMetricsService $financialService,
        private readonly ReadModelAggregationService $readModelService
    ) {
    }

    /**
     * Builds the Executive / Owner Dashboard view model.
     */
    public function buildOwnerDashboard(string $asOfDate, string $currency = 'USD'): OwnerDashboardData
    {
        AnalyticsInput::date($asOfDate);
        [$curr, $places] = AnalyticsInput::currency($this->db, $currency);

        // 1. Subscription & MRR state
        $snapshot = $this->subscriptionService->getSnapshot($asOfDate, $curr);
        if ($snapshot === null) {
            throw new LogicException('Explicit canonical subscription snapshot required; dashboard cannot reconstruct history.');
        }

        $this->verifySnapshot($snapshot, $places);

        // 2. Financial cash flow last 30 days
        $start30d = (new DateTimeImmutable($asOfDate))->modify('-29 days')->format('Y-m-d');
        $financialSummary = $this->financialService->calculatePeriodFinancialMetrics($start30d, $asOfDate, $curr);

        // 3. Aged outstanding receivables
        $aging = $this->financialService->calculateAgingReceivables($asOfDate, $curr);

        // 4. MRR trend (last 6 snapshots)
        $mrrTrend = [];
        $trendSnapshots = $this->subscriptionService->getHistoricalSnapshots($start30d, $asOfDate, $curr);
        foreach ($trendSnapshots as $s) {
            $this->verifySnapshot($s, $places);
            $mrrTrend[$s->getSnapshotDate()] = $s->getTotalMrr();
        }

        $cashTrend = [];
        foreach ($this->readModelService->getDailySeries($start30d, $asOfDate, $curr) as $day) { $cashTrend[$day->getDate()] = $day->getNetCashFlow(); }
        return new OwnerDashboardData(
            asOfDate: $asOfDate,
            currency: $curr,
            totalMrr: $snapshot->getTotalMrr(),
            totalArr: $snapshot->getTotalArr(),
            netCashFlowLast30Days: $financialSummary->getNetCashFlow(),
            netMrrGrowth: $snapshot->getNetMrrGrowth(),
            activeSubscriptions: $snapshot->getActiveSubscriptionsCount(),
            activeCustomers: $snapshot->getActiveCustomersCount(),
            arpu: $snapshot->getArpu(),
            outstandingReceivables: $aging->getTotalOutstanding(),
            mrrTrend: $mrrTrend,
            cashTrend: $cashTrend
        );
    }

    /**
     * Builds the Finance Dashboard view model.
     */
    public function buildFinanceDashboard(string $startDate, string $endDate, string $currency = 'USD'): FinanceDashboardData
    {
        $curr = strtoupper(trim($currency));
        $periodSummary = $this->financialService->calculatePeriodFinancialMetrics($startDate, $endDate, $curr);
        $agingReceivables = $this->financialService->calculateAgingReceivables($endDate, $curr);
        $marginReport = $this->financialService->calculateContributionMargin($startDate, $endDate, $curr);

        return new FinanceDashboardData(
            periodSummary: $periodSummary,
            agingReceivables: $agingReceivables,
            estimatedVatLiability: $periodSummary->getTaxBilled(),
            gatewayFeesTotal: $marginReport->getGatewayFeesTotal(),
            netMarginPercent: $marginReport->getContributionMarginPercent()
        );
    }

    /**
     * Builds the Sales / Growth Dashboard view model.
     */
    public function buildSalesDashboard(string $startDate, string $endDate, string $currency = 'USD'): SalesDashboardData
    {
        [$start, $end] = AnalyticsInput::range($startDate, $endDate);
        [$curr, , $divisor] = AnalyticsInput::currency($this->db, $currency);
        $orders = $this->db->select('SELECT total_minor FROM orders WHERE created_at >= ? AND created_at < ? AND currency_code = ?', [$start, $end, $curr]);
        $orderCount = count($orders);
        $orderVolume = AnalyticsInput::total($orders, 'total_minor') / $divisor;
        $newCustomers = (int) $this->db->selectOne('SELECT COUNT(*) AS n FROM users WHERE created_at >= ? AND created_at < ?', [$start, $end])['n'];
        $newMrr = $this->subscriptionService->getMrrWaterfall($startDate, $endDate, $curr)->getNewMrr();
        $topProducts = [];
        $products = $this->db->select("SELECT p.name, s.billing_cycle, s.recurring_amount_minor FROM services s LEFT JOIN products p ON p.id = s.product_id WHERE s.status = 'active' AND s.currency_code = ?", [$curr]);
        foreach ($products as $product) {
            $cycle = $product['billing_cycle'];
            if (!PriceCycle::isValid($cycle)) { throw new LogicException('Unknown service billing cycle.'); }
            $name = $product['name'];
            if ($name === null) { throw new LogicException('Service product missing; explicit reconciliation required.'); }
            $topProducts[$name] ??= ['active_count' => 0, 'revenue' => 0.0];
            $topProducts[$name]['active_count']++;
            $topProducts[$name]['revenue'] += MrrCalculator::normalizeToMonthly(AnalyticsInput::minor($product['recurring_amount_minor']) / $divisor, $cycle);
        }
        uasort($topProducts, static fn($a, $b) => $b['active_count'] <=> $a['active_count']);
        $topProducts = array_slice($topProducts, 0, 5, true);

        return new SalesDashboardData(
            startDate: $startDate,
            endDate: $endDate,
            currency: $curr,
            newOrdersCount: $orderCount,
            newOrdersVolume: round($orderVolume, 2),
            newCustomersCount: $newCustomers,
            newMrrAdded: $newMrr,
            topSellingProducts: $topProducts
        );
    }

    /**
     * Builds the Operations & Infrastructure Dashboard view model.
     */
    public function buildOperationsDashboard(string $asOfDate): OperationsDashboardData
    {
        AnalyticsInput::date($asOfDate);
        $counts = array_column($this->db->select('SELECT status, COUNT(*) AS n FROM services GROUP BY status'), 'n', 'status');
        $activeServices = (int) ($counts['active'] ?? 0);
        $suspendedServices = (int) ($counts['suspended'] ?? 0);
        $terminatedServices = (int) ($counts['terminated'] ?? 0);
        $domains = $this->db->select("SELECT expiry_date FROM domains WHERE status = 'active'");
        $domainsCount = count($domains); $domainsExpiringSoon = 0;
        $next30d = (new DateTimeImmutable($asOfDate))->modify('+30 days')->format('Y-m-d');
        foreach ($domains as $domain) {
            if ($domain['expiry_date'] === null) { continue; }
            AnalyticsInput::date($domain['expiry_date']);
            if ($domain['expiry_date'] >= $asOfDate && $domain['expiry_date'] <= $next30d) { $domainsExpiringSoon++; }
        }
        $openAbuseCases = (int) $this->db->selectOne("SELECT COUNT(*) AS n FROM abuse_cases WHERE status NOT IN ('resolved', 'closed')")['n'];

        return new OperationsDashboardData(
            asOfDate: $asOfDate,
            activeHostingServices: $activeServices,
            suspendedServices: $suspendedServices,
            terminatedServices: $terminatedServices,
            domainsUnderManagement: $domainsCount,
            domainsExpiringNext30Days: $domainsExpiringSoon,
            openAbuseCasesCount: $openAbuseCases,
            hypervisorCapacityUsedPercent: null
        );
    }

    /**
     * Builds the Support & Service Desk Dashboard view model.
     */
    public function buildSupportDashboard(string $startDate, string $endDate): SupportDashboardData
    {
        [$start, $end] = AnalyticsInput::range($startDate, $endDate);
        $tickets = $this->db->select('SELECT t.department_id, d.name AS department_name, t.priority FROM support_tickets t LEFT JOIN support_departments d ON d.id = t.department_id WHERE t.created_at >= ? AND t.created_at < ?', [$start, $end]);
        $openedCount = count($tickets); $deptBreakdown = []; $priorityBreakdown = [];
        foreach ($tickets as $ticket) {
            $department = $ticket['department_name'] ?? (string) $ticket['department_id'];
            $deptBreakdown[$department] = ($deptBreakdown[$department] ?? 0) + 1;
            $priorityBreakdown[$ticket['priority']] = ($priorityBreakdown[$ticket['priority']] ?? 0) + 1;
        }
        // Current status/updated_at proxy; exact transition history remains an acceptance gate.
        $resolvedCount = (int) $this->db->selectOne("SELECT COUNT(*) AS n FROM support_tickets WHERE status IN ('resolved', 'closed') AND updated_at >= ? AND updated_at < ?", [$start, $end])['n'];
        $openBacklog = (int) $this->db->selectOne("SELECT COUNT(*) AS n FROM support_tickets WHERE status NOT IN ('resolved', 'closed')")['n'];
        $responseMinutes = null; $compliance = null;
        $slaExists = $this->db->getDriverName() === 'mysql'
            ? $this->db->selectOne('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', ['support_ticket_sla'])
            : $this->db->selectOne("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?", ['support_ticket_sla']);
        if ($slaExists !== null) {
            $slaRows = $this->db->select('SELECT t.created_at, s.first_responded_at, s.resolved_at, s.is_first_response_breached, s.is_resolution_breached FROM support_ticket_sla s JOIN support_tickets t ON t.id = s.ticket_id WHERE t.created_at >= ? AND t.created_at < ?', [$start, $end]);
            $responses = []; $evaluated = 0; $met = 0;
            foreach ($slaRows as $sla) {
                if ($sla['first_responded_at'] !== null) {
                    $seconds = (new DateTimeImmutable($sla['first_responded_at']))->getTimestamp() - (new DateTimeImmutable($sla['created_at']))->getTimestamp();
                    if ($seconds < 0) { throw new LogicException('Invalid support response timestamp.'); }
                    $responses[] = $seconds / 60;
                }
                if ($sla['resolved_at'] !== null) {
                    $evaluated++;
                    if (!(bool) $sla['is_first_response_breached'] && !(bool) $sla['is_resolution_breached']) { $met++; }
                }
            }
            if ($responses !== []) { $responseMinutes = round(array_sum($responses) / count($responses), 2); }
            if ($evaluated > 0) { $compliance = round($met / $evaluated * 100, 2); }
        }
        return new SupportDashboardData($startDate, $endDate, $openedCount, $resolvedCount, $openBacklog, $responseMinutes, $compliance, $deptBreakdown, $priorityBreakdown);
    }

    private function verifySnapshot(SubscriptionSnapshot $snapshot, int $places): void
    {
        $metadata = $snapshot->getMetadata();
        if (($metadata['source'] ?? null) !== 'services:canonical_minor_units' || ($metadata['currency_decimal_places'] ?? null) !== $places) {
            throw new LogicException('Subscription snapshot source/precision requires explicit reconciliation.');
        }
    }
}
