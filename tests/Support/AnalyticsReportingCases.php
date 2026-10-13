<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;

use Coleza\Domain\Analytics\Financial\FinancialMetricsService;
use Coleza\Domain\Analytics\ReadModels\ReadModelAggregationService;
use Coleza\Domain\Analytics\Subscription\SubscriptionSnapshotService;
use Coleza\Domain\Analytics\Reconciliation\LedgerReconciliationService;
use Coleza\Domain\Analytics\Context\AnalyticsFreshnessService;
use LogicException;
use PDOException;

trait AnalyticsReportingCases
{
    private function reportingFixture(): ReadModelAggregationService
    {
        DailySourceFixture::install($this->db);
        return new ReadModelAggregationService($this->db);
    }
    public function testCanonicalPeriodMetricsAndDailyMetricsAgree(): void
    {
        $read = $this->reportingFixture();
        DailySourceFixture::invoice($this->db, 12345, 10000, 2345);
        DailySourceFixture::payment($this->db, 10000, 350); DailySourceFixture::refund($this->db, 1500);
        DailySourceFixture::invoice($this->db, 77700, 77700, 0, code: 'EUR');
        $day = $read->aggregateDay('2026-10-13');
        $finance = new FinancialMetricsService($this->db);
        $period = $finance->calculatePeriodFinancialMetrics('2026-10-13', '2026-10-13', ' usd ');
        self::assertSame(123.45, $period->getGrossInvoiced()); self::assertSame($day->getNetInvoiced(), $period->getNetInvoiced());
        self::assertSame(85.0, $period->getNetCashFlow());
        self::assertSame(3.5, $finance->calculateContributionMargin('2026-10-13', '2026-10-13')->getGatewayFeesTotal());
    }
    public function testMissingLedgerCannotBeReportedAsMatchedZero(): void
    {
        $this->reportingFixture();
        $this->db->statement('ALTER TABLE financial_account_transactions RENAME TO fixture_saved_ledger');
        $this->expectException(PDOException::class);
        (new LedgerReconciliationService($this->db))->reconcile('2026-10-01', '2026-10-31');
    }
    public function testLegacyDailySeriesCannotBeReadAsVerified(): void
    {
        $read = $this->reportingFixture();
        $this->db->insert('analytics_daily_aggregations', ['date' => '2026-10-13', 'currency' => 'USD', 'gross_invoiced' => 77]);
        $this->expectException(LogicException::class);
        $read->getDailySeries('2026-10-01', '2026-10-31');
    }
    public function testChangedMonthlySeriesCannotBeReadAsVerified(): void
    {
        $read = $this->reportingFixture(); $read->rebuildDateRange('2026-10-01', '2026-10-31');
        (new SubscriptionSnapshotService($this->db))->captureSnapshot('2026-10-31');
        $read->aggregateMonth('2026-10');
        $this->db->statement('UPDATE analytics_monthly_aggregations SET gross_invoiced = 77');
        $this->expectException(LogicException::class);
        $read->getMonthlySeries('2026-10', '2026-10');
    }
    public function testAbsentDataDoesNotProveRealtimeConsistency(): void
    {
        $this->reportingFixture();
        $freshness = (new AnalyticsFreshnessService($this->db))->evaluateFreshness();
        self::assertFalse($freshness->isConsistent());
    }
    public function testFailedSecondDayRollsBackWholeRebuild(): void
    {
        $read = $this->reportingFixture();
        $this->db->insert('analytics_daily_aggregations', ['date' => '2026-10-01', 'currency' => 'USD', 'gross_invoiced' => 77]);
        $before = $this->db->select('SELECT * FROM analytics_daily_aggregations');
        $this->db->statement($this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER fixture_fail_second BEFORE INSERT ON analytics_daily_aggregations FOR EACH ROW BEGIN IF NEW.date = '2026-10-02' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture second day failure'; END IF; END"
            : "CREATE TRIGGER fixture_fail_second BEFORE INSERT ON analytics_daily_aggregations WHEN NEW.date = '2026-10-02' BEGIN SELECT RAISE(ABORT, 'fixture second day failure'); END");
        try { $read->rebuildDateRange('2026-10-01', '2026-10-03'); self::fail('Injected failure ignored'); }
        catch (PDOException) { self::assertSame($before, $this->db->select('SELECT * FROM analytics_daily_aggregations')); }
    }

    public function testVerifiedSeriesReadDoesNotWriteOrInstallSchema(): void
    {
        $read = $this->reportingFixture(); $read->rebuildDateRange('2026-10-01', '2026-10-31');
        (new SubscriptionSnapshotService($this->db))->captureSnapshot('2026-10-31'); $read->aggregateMonth('2026-10');
        $before = $this->db->select('SELECT * FROM analytics_monthly_aggregations');
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        self::assertCount(31, $read->getDailySeries('2026-10-01', '2026-10-31', ' usd '));
        self::assertCount(1, $read->getMonthlySeries('2026-10', '2026-10', ' usd '));
        foreach ($this->db->getQueryLog() as $query) { self::assertDoesNotMatchRegularExpression('/^\s*(CREATE|ALTER|DROP|INSERT|UPDATE|DELETE|TRUNCATE)\b/i', $query['query']); }
        self::assertSame($before, $this->db->select('SELECT * FROM analytics_monthly_aggregations'));
    }
    public function testAbsentSchemaReadCannotBootstrapTables(): void
    {
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        try { (new ReadModelAggregationService($this->db))->getDailySeries('2026-10-01', '2026-10-31'); self::fail('Absent schema accepted'); }
        catch (LogicException) {
            foreach ($this->db->getQueryLog() as $query) { self::assertDoesNotMatchRegularExpression('/^\s*(CREATE|ALTER|DROP)\b/i', $query['query']); }
        }
    }
    public function testRawCallerKeepsEarlierWorkWhenAuditFails(): void
    {
        $this->auditFailureRollback(false);
    }
    public function testManagedCallerKeepsEarlierWorkWhenAuditFails(): void
    {
        $this->auditFailureRollback(true);
    }
    private function auditFailureRollback(bool $managed): void
    {
        $this->reportingFixture();
        $this->db->statement('CREATE TABLE reporting_caller_marker (id INT PRIMARY KEY)');
        $this->db->statement($this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER fixture_fail_audit BEFORE INSERT ON security_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture audit failure'"
            : "CREATE TRIGGER fixture_fail_audit BEFORE INSERT ON security_audit_logs BEGIN SELECT RAISE(ABORT, 'fixture audit failure'); END");
        $managed ? $this->db->beginTransaction() : $this->db->getPdo()->beginTransaction();
        try {
            $this->db->insert('reporting_caller_marker', ['id' => 1]);
            $read = new ReadModelAggregationService($this->db, new \Coleza\Domain\Identity\Audit\AuditLogger($this->db));
            try { $read->rebuildDateRange('2026-10-01', '2026-10-03'); self::fail('Audit failure ignored'); }
            catch (PDOException) {
                self::assertTrue($this->db->getPdo()->inTransaction());
                self::assertCount(1, $this->db->select('SELECT * FROM reporting_caller_marker'));
                self::assertSame([], $this->db->select('SELECT * FROM analytics_daily_aggregations'));
                self::assertSame([], $this->db->select('SELECT * FROM security_audit_logs'));
            }
        } finally { $managed ? $this->db->rollBack() : $this->db->getPdo()->rollBack(); }
        self::assertSame([], $this->db->select('SELECT * FROM reporting_caller_marker'));
    }
    public function testStandaloneAuditFailureRollsBackSuccessfulDays(): void
    {
        $this->reportingFixture();
        $this->db->statement($this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER fixture_fail_audit BEFORE INSERT ON security_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture audit failure'"
            : "CREATE TRIGGER fixture_fail_audit BEFORE INSERT ON security_audit_logs BEGIN SELECT RAISE(ABORT, 'fixture audit failure'); END");
        try { (new ReadModelAggregationService($this->db, new \Coleza\Domain\Identity\Audit\AuditLogger($this->db)))->rebuildDateRange('2026-10-01', '2026-10-03'); self::fail('Audit failure ignored'); }
        catch (PDOException) { self::assertSame([], $this->db->select('SELECT * FROM analytics_daily_aggregations')); self::assertFalse($this->db->getPdo()->inTransaction()); }
    }
    public function testFreshnessRejectsLegacyAndFutureEvidence(): void
    {
        $read = $this->reportingFixture(); $today = date('Y-m-d'); $read->aggregateDay($today);
        $row = $this->db->selectOne('SELECT * FROM analytics_daily_aggregations LIMIT 1');
        $row['rebuilt_at'] = date('Y-m-d H:i:s', time() + 3600);
        $this->db->update('analytics_daily_aggregations', ['rebuilt_at' => $row['rebuilt_at'], 'calculation_evidence' => \Coleza\Domain\Analytics\ReadModels\DailyAggregationEvidence::create($row, 2)], 'id = :id', ['id' => $row['id']]);
        $freshness = new AnalyticsFreshnessService($this->db);
        self::assertSame(\Coleza\Domain\Analytics\Context\DataFreshnessStatus::UNKNOWN, $freshness->evaluateFreshness()->getStatus());
        $this->db->statement('UPDATE analytics_daily_aggregations SET calculation_evidence = NULL');
        self::assertFalse($freshness->evaluateFreshness()->isConsistent());
    }
    public function testBackdatedRefundChangesFreshnessOnlyForItsCurrency(): void
    {
        $read = $this->reportingFixture(); $date = date('Y-m-d'); $read->aggregateDay($date);
        DailySourceFixture::refund($this->db, 1500, 'EUR', $date . ' 00:00:00');
        $freshness = new AnalyticsFreshnessService($this->db);
        self::assertTrue($freshness->evaluateFreshness()->isConsistent());
        DailySourceFixture::refund($this->db, 1500, 'USD', $date . ' 00:00:00');
        self::assertFalse($freshness->evaluateFreshness()->isConsistent());
        self::assertSame(\Coleza\Domain\Analytics\Context\DataFreshnessStatus::STALE, $freshness->evaluateFreshness()->getStatus());
    }
    public function testNewEventDayWithoutRollupIsInconsistent(): void
    {
        $read = $this->reportingFixture(); $yesterday = date('Y-m-d', time() - 86400); $read->aggregateDay($yesterday);
        DailySourceFixture::payment($this->db, 5000, paidAt: date('Y-m-d') . ' 00:00:00');
        self::assertFalse((new AnalyticsFreshnessService($this->db))->evaluateFreshness()->isConsistent());
    }
    public function testMissingFinancialSourceCannotProveFreshness(): void
    {
        $read = $this->reportingFixture(); $read->aggregateDay(date('Y-m-d'));
        $this->db->statement('ALTER TABLE refunds RENAME TO fixture_saved_refunds');
        $freshness = (new AnalyticsFreshnessService($this->db))->evaluateFreshness();
        self::assertFalse($freshness->isConsistent()); self::assertNull($freshness->getLagSeconds());
    }
    public function testAgingUsesPartialBalanceAndCurrencyAndExcludesFutureInvoices(): void
    {
        $this->reportingFixture();
        DailySourceFixture::invoice($this->db, 15000, 15000, 0, 'partially_paid', date: '2026-10-01');
        $this->db->statement('UPDATE invoices SET paid_amount_minor = 5000, due_date = ?', ['2026-10-01']);
        DailySourceFixture::invoice($this->db, 80000, 80000, 0, code: 'EUR');
        DailySourceFixture::invoice($this->db, 80000, 80000, 0, date: '2026-11-01');
        $aging = (new FinancialMetricsService($this->db))->calculateAgingReceivables('2026-10-31');
        self::assertSame(100.0, $aging->getTotalOutstanding()); self::assertSame(1, $aging->getOverdueInvoicesCount());
    }
    public function testCurrencyPrecisionZeroUsesActualDivisorAcrossLiveAndLedger(): void
    {
        $this->reportingFixture(); DailySourceFixture::payment($this->db, 12345, 45, code: 'JPY');
        $this->db->insert('financial_accounts', ['id' => 1, 'code' => 'JPY-FIX', 'name' => 'Fixture', 'account_type' => 'bank', 'currency_code' => 'JPY']);
        $this->db->insert('financial_account_transactions', ['entry_number' => 'JPY-1', 'account_id' => 1, 'type' => 'credit', 'amount_minor' => 12345, 'balance_after_minor' => 12345, 'currency_code' => 'JPY', 'source' => 'payment', 'description' => 'Fixture', 'transaction_date' => '2026-10-13']);
        $finance = new FinancialMetricsService($this->db);
        self::assertSame(12345.0, $finance->calculatePeriodFinancialMetrics('2026-10-13', '2026-10-13', 'JPY')->getNetCashFlow());
        self::assertTrue((new LedgerReconciliationService($this->db))->reconcile('2026-10-13', '2026-10-13', 'JPY')->isReconciled());
    }
    public function testGoldenMoneyAndCorruptRollupUseProductionSchema(): void
    {
        \Coleza\Domain\Analytics\Testing\GoldenFinancialDataset::seed($this->db);
        $ledger = new LedgerReconciliationService($this->db);
        self::assertTrue($ledger->reconcile('2026-10-01', '2026-10-31')->isReconciled());
        \Coleza\Domain\Analytics\Testing\GoldenFinancialDataset::injectAnalyticsDiscrepancy($this->db);
        $report = $ledger->reconcile('2026-10-01', '2026-10-31');
        self::assertFalse($report->isReconciled()); self::assertSame(50.0, $report->getPaymentVariance());
        self::assertStringContainsString('Unverified daily', implode(' ', $report->getDiscrepancies()));
    }

    private function reportingDashboards(): \Coleza\Domain\Analytics\Dashboards\DashboardService
    {
        return new \Coleza\Domain\Analytics\Dashboards\DashboardService($this->db, new SubscriptionSnapshotService($this->db), new FinancialMetricsService($this->db), new ReadModelAggregationService($this->db));
    }
    public function testOwnerDashboardDoesNotInventHistoricalSnapshot(): void
    {
        $this->reportingFixture(); SubscriptionSourceFixture::service($this->db, 5000);
        try { $this->reportingDashboards()->buildOwnerDashboard('2020-01-01'); self::fail('Historical snapshot invented'); }
        catch (LogicException) { self::assertSame([], $this->db->select('SELECT * FROM analytics_subscription_snapshots')); }
    }
    public function testSalesUsesCurrencyMinorUnitsAndCanonicalProductRevenue(): void
    {
        $this->reportingFixture();
        $this->db->insert('products', ['id' => 1, 'group_id' => 1, 'slug' => 'annual', 'name' => 'Annual', 'type' => 'hosting']);
        SubscriptionSourceFixture::service($this->db, 12000, cycle: 'annually');
        foreach (['USD' => 20000, 'EUR' => 99999] as $code => $amount) {
            $this->db->insert('orders', ['order_number' => 'ORD-' . $code, 'user_id' => 1, 'currency_code' => $code, 'total_minor' => $amount, 'created_at' => '2026-10-13 00:00:00']);
        }
        $sales = $this->reportingDashboards()->buildSalesDashboard('2026-10-13', '2026-10-13');
        self::assertSame(1, $sales->getNewOrdersCount()); self::assertSame(200.0, $sales->getNewOrdersVolume());
        self::assertSame(10.0, $sales->getTopSellingProducts()['Annual']['revenue']);
    }
    public function testUnavailableProviderAndSlaValuesRemainNull(): void
    {
        $this->reportingFixture(); $dashboards = $this->reportingDashboards();
        self::assertNull($dashboards->buildOperationsDashboard('2026-10-13')->getHypervisorCapacityUsedPercent());
        $support = $dashboards->buildSupportDashboard('2026-10-01', '2026-10-31');
        self::assertNull($support->getFirstResponseTimeAvgMinutes()); self::assertNull($support->getSlaComplianceRatePercent());
    }
    public function testSupportUsesActualResponseAndCompletedSlaResultsAndGlobalBacklog(): void
    {
        $this->reportingFixture();
        $departments = new \Coleza\Domain\Support\Departments\DepartmentService($this->db);
        $tickets = new \Coleza\Domain\Support\Tickets\TicketService($this->db, $departments);
        (new \Coleza\Domain\Support\Sla\SlaService($this->db, $tickets))->ensureTables();
        $this->db->insert('support_departments', ['id' => 1, 'name' => 'billing']);
        foreach ([[1, '2026-10-05', 'resolved'], [2, '2026-10-06', 'closed'], [3, '2026-09-01', 'open']] as [$id, $date, $status]) {
            $this->db->insert('support_tickets', ['id' => $id, 'ticket_number' => 'SLA-' . $id, 'user_id' => 1, 'department_id' => 1, 'subject' => 'Fixture', 'status' => $status, 'priority' => 'medium', 'created_at' => $date . ' 10:00:00', 'updated_at' => $date . ' 11:00:00']);
        }
        foreach ([[1, '2026-10-05', 10, 0], [2, '2026-10-06', 30, 1]] as [$id, $date, $minutes, $breached]) {
            $this->db->insert('support_ticket_sla', ['ticket_id' => $id, 'first_response_due_at' => $date . ' 10:20:00', 'resolution_due_at' => $date . ' 11:00:00', 'first_responded_at' => $date . ' 10:' . $minutes . ':00', 'resolved_at' => $date . ' 11:00:00', 'is_first_response_breached' => $breached]);
        }
        $support = $this->reportingDashboards()->buildSupportDashboard('2026-10-01', '2026-10-31');
        self::assertSame(2, $support->getTicketsOpenedCount()); self::assertSame(2, $support->getTicketsResolvedCount());
        self::assertSame(1, $support->getOpenTicketsBacklog()); self::assertSame(20.0, $support->getFirstResponseTimeAvgMinutes());
        self::assertSame(50.0, $support->getSlaComplianceRatePercent());
    }
    public function testSummaryCurrencyMismatchCannotBeReconciled(): void
    {
        $this->reportingFixture(); $summary = (new FinancialMetricsService($this->db))->calculatePeriodFinancialMetrics('2026-10-01', '2026-10-31');
        $this->expectException(LogicException::class);
        (new LedgerReconciliationService($this->db))->reconcileFinancialPeriodSummary($summary, '2026-10-01', '2026-10-31', 'JPY');
    }
    public function testInvalidCalendarAndNegativeCostsRefuse(): void
    {
        $this->reportingFixture(); $finance = new FinancialMetricsService($this->db);
        foreach ([['2026-02-30', 0.0], ['2026-10-01', -1.0], ['2026-10-01', INF]] as [$date, $cost]) {
            try { $finance->calculateContributionMargin($date, '2026-10-31', directCostsTotal: $cost); self::fail('Invalid input accepted'); }
            catch (\Coleza\Foundation\Exceptions\ValidationException) { self::assertTrue(true); }
        }
    }
}
