<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\ReadModels;

use Coleza\Domain\Audit\AuditLogger;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Database\PdoSchema;
use Coleza\Domain\Analytics\AnalyticsSchema;
use Coleza\Foundation\Exceptions\ValidationException;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use LogicException;

final class ReadModelAggregationService
{
    private string $dailyTable = 'analytics_daily_aggregations';
    private string $monthlyTable = 'analytics_monthly_aggregations';

    public function __construct(
        private readonly Connection $db,
        private readonly ?AuditLogger $auditLogger = null
    ) {
    }

    public static function schemaRequirements(): array
    {
        return [
            'analytics_daily_aggregations' => ['id', 'date', 'currency', 'gross_invoiced', 'net_invoiced', 'tax_billed', 'invoices_count', 'cash_collected', 'cash_refunded', 'net_cash_flow', 'gateway_fees', 'new_orders_count', 'new_customers_count', 'tickets_opened_count', 'tickets_resolved_count', 'rebuilt_at'],
            'analytics_monthly_aggregations' => ['id', 'year_month', 'currency', 'gross_invoiced', 'net_invoiced', 'tax_billed', 'cash_collected', 'cash_refunded', 'net_cash_flow', 'gateway_fees', 'end_of_month_mrr', 'end_of_month_arr', 'new_subscriptions_count', 'churned_subscriptions_count', 'rebuilt_at'],
        ];
    }

    public static function indexRequirements(): array
    {
        return ['analytics_daily_aggregations' => ['date', 'uq_daily_agg'], 'analytics_monthly_aggregations' => ['year_month', 'uq_monthly_agg']];
    }

    public function ensureTables(bool $installSchema = false): void
    {
        AnalyticsSchema::assertCaller($this->db);
        $installed = PdoSchema::useInstalledSchema($this->db, self::schemaRequirements(), $installSchema);
        if ($installed) { AnalyticsSchema::ensureIndexes($this->db, self::indexRequirements(), $installSchema); return; }
        AnalyticsSchema::preflightExisting($this->db, self::indexRequirements());
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        $sqlDaily = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                date VARCHAR(10) NOT NULL,
                currency VARCHAR(3) NOT NULL,
                gross_invoiced DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                net_invoiced DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                tax_billed DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                invoices_count INT NOT NULL DEFAULT 0,
                cash_collected DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                cash_refunded DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                net_cash_flow DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                gateway_fees DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                new_orders_count INT NOT NULL DEFAULT 0,
                new_customers_count INT NOT NULL DEFAULT 0,
                tickets_opened_count INT NOT NULL DEFAULT 0,
                tickets_resolved_count INT NOT NULL DEFAULT 0,
                rebuilt_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->dailyTable,
            $autoInc
        );
        $this->db->statement($sqlDaily . ($driver === 'mysql' ? ' ENGINE=InnoDB' : ''));

        $sqlMonthly = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                `year_month` VARCHAR(7) NOT NULL,
                currency VARCHAR(3) NOT NULL,
                gross_invoiced DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                net_invoiced DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                tax_billed DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                cash_collected DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                cash_refunded DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                net_cash_flow DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                gateway_fees DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                end_of_month_mrr DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                end_of_month_arr DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                new_subscriptions_count INT NOT NULL DEFAULT 0,
                churned_subscriptions_count INT NOT NULL DEFAULT 0,
                rebuilt_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->monthlyTable,
            $autoInc
        );
        $this->db->statement($sqlMonthly . ($driver === 'mysql' ? ' ENGINE=InnoDB' : ''));
        AnalyticsSchema::ensureIndexes($this->db, self::indexRequirements(), true);
    }

    /**
     * Computes and persists daily read model aggregation for a single date.
     */
    public function aggregateDay(string $date, string $currency = 'USD'): DailyAggregation
    {
        AnalyticsSchema::assertCaller($this->db);
        $this->validateDateFormat($date);
        $curr = strtoupper(trim($currency));

        $divisor = $this->dailyCurrencyDivisor($curr);
        $startAt = $date . ' 00:00:00';
        $endAt = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        $bindings = ['currency' => $curr, 'start' => $startAt, 'end' => $endAt];

        // Issue date records invoicing; settlement/refund dates record cash. Creation is not payment.
        if ($this->db->selectOne("SELECT id FROM invoices WHERE currency_code = ? AND status IN ('unpaid', 'paid', 'partially_paid', 'refunded') AND (issue_date IS NULL OR TRIM(issue_date) = '') LIMIT 1", [$curr]) !== null) {
            throw new LogicException('Issued invoice without event date requires explicit reconciliation before analytics.');
        }
        $invoices = $this->db->select('SELECT total_minor, subtotal_minor, tax_total_minor, status FROM invoices WHERE issue_date = :date AND currency_code = :currency', ['date' => $date, 'currency' => $curr]);
        $issued = [];
        foreach ($invoices as $invoice) {
            if (in_array($invoice['status'], ['draft', 'cancelled'], true)) { continue; }
            if (!in_array($invoice['status'], ['unpaid', 'paid', 'partially_paid', 'refunded'], true)) { throw new LogicException('Unknown invoice state requires explicit reconciliation before analytics.'); }
            $issued[] = $invoice;
        }

        if ($this->db->selectOne("SELECT id FROM payments WHERE currency_code = ? AND status IN ('completed', 'partially_refunded', 'refunded') AND paid_at IS NULL LIMIT 1", [$curr]) !== null) {
            throw new LogicException('Settled payment without event date requires explicit reconciliation before analytics.');
        }
        if ($this->db->selectOne('SELECT id FROM refunds WHERE currency_code = ? AND refunded_at IS NULL LIMIT 1', [$curr]) !== null) {
            throw new LogicException('Refund without event date requires explicit reconciliation before analytics.');
        }
        $payments = $this->db->select('SELECT amount_minor, fee_minor, status FROM payments WHERE currency_code = :currency AND paid_at >= :start AND paid_at < :end', $bindings);
        $settled = [];
        foreach ($payments as $payment) {
            if (in_array($payment['status'], ['pending', 'failed'], true)) { continue; }
            if (!in_array($payment['status'], ['completed', 'partially_refunded', 'refunded'], true)) { throw new LogicException('Unknown payment state requires explicit reconciliation before analytics.'); }
            $settled[] = $payment;
        }
        // Persisted refunds are successful business records; attempts/reservations are separate tables.
        $refunds = $this->db->select('SELECT amount_minor FROM refunds WHERE currency_code = :currency AND refunded_at >= :start AND refunded_at < :end', $bindings);
        $grossInvoiced = round($this->dailyMinorTotal($issued, 'total_minor') / $divisor, 2);
        $netInvoiced = round($this->dailyMinorTotal($issued, 'subtotal_minor') / $divisor, 2);
        $taxBilled = round($this->dailyMinorTotal($issued, 'tax_total_minor') / $divisor, 2);
        $invoicesCount = count($issued);
        $cashCollected = round($this->dailyMinorTotal($settled, 'amount_minor') / $divisor, 2);
        $gatewayFees = round($this->dailyMinorTotal($settled, 'fee_minor') / $divisor, 2);
        $cashRefunded = round($this->dailyMinorTotal($refunds, 'amount_minor') / $divisor, 2);
        $netCashFlow = round($cashCollected - $cashRefunded, 2);

        // All source reads must succeed before replacing a daily record. User/ticket counters remain global.
        $newOrdersCount = (int) $this->db->selectOne('SELECT COUNT(*) AS n FROM orders WHERE currency_code = :currency AND created_at >= :start AND created_at < :end', $bindings)['n'];
        $timeBindings = ['start' => $startAt, 'end' => $endAt];
        $newCustomersCount = (int) $this->db->selectOne('SELECT COUNT(*) AS n FROM users WHERE created_at >= :start AND created_at < :end', $timeBindings)['n'];
        $ticketsOpened = (int) $this->db->selectOne('SELECT COUNT(*) AS n FROM support_tickets WHERE created_at >= :start AND created_at < :end', $timeBindings)['n'];
        $ticketsResolved = (int) $this->db->selectOne("SELECT COUNT(*) AS n FROM support_tickets WHERE updated_at >= :start AND updated_at < :end AND status = 'resolved'", $timeBindings)['n'];
        $this->ensureTables();
        $now = date('Y-m-d H:i:s');

        // Upsert into daily table
        $existing = $this->db->selectOne(
            "SELECT id FROM {$this->dailyTable} WHERE date = :d AND currency = :c LIMIT 1",
            ['d' => $date, 'c' => $curr]
        );

        if ($existing !== null) {
            $this->db->update(
                $this->dailyTable,
                [
                    'gross_invoiced' => $grossInvoiced,
                    'net_invoiced' => $netInvoiced,
                    'tax_billed' => $taxBilled,
                    'invoices_count' => $invoicesCount,
                    'cash_collected' => $cashCollected,
                    'cash_refunded' => $cashRefunded,
                    'net_cash_flow' => $netCashFlow,
                    'gateway_fees' => $gatewayFees,
                    'new_orders_count' => $newOrdersCount,
                    'new_customers_count' => $newCustomersCount,
                    'tickets_opened_count' => $ticketsOpened,
                    'tickets_resolved_count' => $ticketsResolved,
                    'rebuilt_at' => $now,
                ],
                'id = :id',
                ['id' => $existing['id']]
            );
            $id = (int) $existing['id'];
        } else {
            $this->db->insert($this->dailyTable, [
                'date' => $date,
                'currency' => $curr,
                'gross_invoiced' => $grossInvoiced,
                'net_invoiced' => $netInvoiced,
                'tax_billed' => $taxBilled,
                'invoices_count' => $invoicesCount,
                'cash_collected' => $cashCollected,
                'cash_refunded' => $cashRefunded,
                'net_cash_flow' => $netCashFlow,
                'gateway_fees' => $gatewayFees,
                'new_orders_count' => $newOrdersCount,
                'new_customers_count' => $newCustomersCount,
                'tickets_opened_count' => $ticketsOpened,
                'tickets_resolved_count' => $ticketsResolved,
                'rebuilt_at' => $now,
            ]);
            $row = $this->db->selectOne(
                "SELECT id FROM {$this->dailyTable} WHERE date = :d AND currency = :c ORDER BY id DESC LIMIT 1",
                ['d' => $date, 'c' => $curr]
            );
            $id = $row !== null ? (int) $row['id'] : null;
        }

        return new DailyAggregation(
            id: $id,
            date: $date,
            currency: $curr,
            grossInvoiced: $grossInvoiced,
            netInvoiced: $netInvoiced,
            taxBilled: $taxBilled,
            invoicesCount: $invoicesCount,
            cashCollected: $cashCollected,
            cashRefunded: $cashRefunded,
            netCashFlow: $netCashFlow,
            gatewayFees: $gatewayFees,
            newOrdersCount: $newOrdersCount,
            newCustomersCount: $newCustomersCount,
            ticketsOpenedCount: $ticketsOpened,
            ticketsResolvedCount: $ticketsResolved,
            rebuiltAt: $now
        );
    }

    /**
     * Computes and persists monthly read model aggregation for a single year-month (YYYY-MM).
     */
    public function aggregateMonth(string $yearMonth, string $currency = 'USD'): MonthlyAggregation
    {
        $this->ensureTables();
        if (!preg_match('/^\d{4}-\d{2}$/', $yearMonth)) {
            throw new ValidationException(['year_month' => ['Month format must be YYYY-MM.']], 'Month format must be YYYY-MM.');
        }

        $curr = strtoupper(trim($currency));
        $startDate = $yearMonth . '-01';
        $endDate = date('Y-m-t', strtotime($startDate));

        // Sum pre-aggregated daily rows if present, or query operational tables
        $dailyRows = $this->db->select(
            "SELECT SUM(gross_invoiced) as gross_inv, SUM(net_invoiced) as net_inv,
                    SUM(tax_billed) as tax_bil, SUM(cash_collected) as cash_col,
                    SUM(cash_refunded) as cash_ref, SUM(gateway_fees) as fees
             FROM {$this->dailyTable}
             WHERE date >= :s AND date <= :e AND currency = :c",
            ['s' => $startDate, 'e' => $endDate, 'c' => $curr]
        );

        $grossInvoiced = (float) ($dailyRows[0]['gross_inv'] ?? 0.0);
        $netInvoiced = (float) ($dailyRows[0]['net_inv'] ?? 0.0);
        $taxBilled = (float) ($dailyRows[0]['tax_bil'] ?? 0.0);
        $cashCollected = (float) ($dailyRows[0]['cash_col'] ?? 0.0);
        $cashRefunded = (float) ($dailyRows[0]['cash_ref'] ?? 0.0);
        $gatewayFees = (float) ($dailyRows[0]['fees'] ?? 0.0);
        $netCashFlow = round($cashCollected - $cashRefunded, 2);

        // Subscriptions end of month
        $endMrr = 0.0;
        $endArr = 0.0;
        $newSubs = 0;
        $churnedSubs = 0;

        try {
            $snap = $this->db->selectOne(
                "SELECT total_mrr, total_arr, new_mrr, churned_mrr FROM analytics_subscription_snapshots
                 WHERE snapshot_date <= :e AND currency = :c ORDER BY snapshot_date DESC LIMIT 1",
                ['e' => $endDate, 'c' => $curr]
            );
            if ($snap !== null) {
                $endMrr = (float) $snap['total_mrr'];
                $endArr = (float) $snap['total_arr'];
            }
        } catch (\Throwable) {
            // Ignore
        }

        $now = date('Y-m-d H:i:s');

        $existing = $this->db->selectOne(
            "SELECT id FROM {$this->monthlyTable} WHERE `year_month` = :ym AND currency = :c LIMIT 1",
            ['ym' => $yearMonth, 'c' => $curr]
        );

        if ($existing !== null) {
            $this->db->update(
                $this->monthlyTable,
                [
                    'gross_invoiced' => $grossInvoiced,
                    'net_invoiced' => $netInvoiced,
                    'tax_billed' => $taxBilled,
                    'cash_collected' => $cashCollected,
                    'cash_refunded' => $cashRefunded,
                    'net_cash_flow' => $netCashFlow,
                    'gateway_fees' => $gatewayFees,
                    'end_of_month_mrr' => $endMrr,
                    'end_of_month_arr' => $endArr,
                    'new_subscriptions_count' => $newSubs,
                    'churned_subscriptions_count' => $churnedSubs,
                    'rebuilt_at' => $now,
                ],
                'id = :id',
                ['id' => $existing['id']]
            );
            $id = (int) $existing['id'];
        } else {
            $this->insertMonthly([
                'year_month' => $yearMonth,
                'currency' => $curr,
                'gross_invoiced' => $grossInvoiced,
                'net_invoiced' => $netInvoiced,
                'tax_billed' => $taxBilled,
                'cash_collected' => $cashCollected,
                'cash_refunded' => $cashRefunded,
                'net_cash_flow' => $netCashFlow,
                'gateway_fees' => $gatewayFees,
                'end_of_month_mrr' => $endMrr,
                'end_of_month_arr' => $endArr,
                'new_subscriptions_count' => $newSubs,
                'churned_subscriptions_count' => $churnedSubs,
                'rebuilt_at' => $now,
            ]);
            $row = $this->db->selectOne(
                "SELECT id FROM {$this->monthlyTable} WHERE `year_month` = :ym AND currency = :c ORDER BY id DESC LIMIT 1",
                ['ym' => $yearMonth, 'c' => $curr]
            );
            $id = $row !== null ? (int) $row['id'] : null;
        }

        return new MonthlyAggregation(
            id: $id,
            yearMonth: $yearMonth,
            currency: $curr,
            grossInvoiced: $grossInvoiced,
            netInvoiced: $netInvoiced,
            taxBilled: $taxBilled,
            cashCollected: $cashCollected,
            cashRefunded: $cashRefunded,
            netCashFlow: $netCashFlow,
            gatewayFees: $gatewayFees,
            endOfMonthMrr: $endMrr,
            endOfMonthArr: $endArr,
            newSubscriptionsCount: $newSubs,
            churnedSubscriptionsCount: $churnedSubs,
            rebuiltAt: $now
        );
    }

    /**
     * Rebuilds/backfills daily read model aggregations across a date range.
     */
    public function rebuildDateRange(string $startDate, string $endDate, string $currency = 'USD'): RebuildExecutionReport
    {
        $this->validateDateFormat($startDate);
        $this->validateDateFormat($endDate);
        if ($startDate > $endDate) {
            throw new ValidationException(['dates' => ['Start date cannot be after end date.']], 'Start date cannot be after end date.');
        }

        $startTime = microtime(true);
        $start = new DateTimeImmutable($startDate);
        $end = (new DateTimeImmutable($endDate))->modify('+1 day');
        $interval = new DateInterval('P1D');
        $period = new DatePeriod($start, $interval, $end);

        $count = 0;
        foreach ($period as $dt) {
            $this->aggregateDay($dt->format('Y-m-d'), $currency);
            $count++;
        }

        $duration = round(microtime(true) - $startTime, 4);
        $now = date('Y-m-d H:i:s');
        $checksum = hash('sha256', sprintf('rebuild_daily:%s:%s:%d:%s', $startDate, $endDate, $count, $now));

        $this->auditLogger?->log(
            actorUserId: 0,
            eventType: 'ANALYTICS_READ_MODELS_REBUILT',
            targetResource: 'analytics:daily_rebuild',
            payload: [
                'scope' => 'daily',
                'count' => $count,
                'start' => $startDate,
                'end' => $endDate,
                'duration' => $duration,
            ]
        );

        return new RebuildExecutionReport(
            scope: 'daily',
            itemsRebuiltCount: $count,
            startDate: $startDate,
            endDate: $endDate,
            currency: strtoupper($currency),
            durationSeconds: $duration,
            completedAt: $now,
            rebuildChecksum: $checksum
        );
    }

    /**
     * Retrieves pre-aggregated daily series for dashboard charts.
     *
     * @return array<DailyAggregation>
     */
    public function getDailySeries(string $startDate, string $endDate, string $currency = 'USD'): array
    {
        $this->ensureTables();
        $rows = $this->db->select(
            "SELECT * FROM {$this->dailyTable}
             WHERE date >= :s AND date <= :e AND currency = :c
             ORDER BY date ASC",
            ['s' => $startDate, 'e' => $endDate, 'c' => strtoupper($currency)]
        );

        return array_map(fn(array $r) => DailyAggregation::fromArray($r), $rows);
    }

    /**
     * Retrieves pre-aggregated monthly series for high-level management reports.
     *
     * @return array<MonthlyAggregation>
     */
    public function getMonthlySeries(string $startYearMonth, string $endYearMonth, string $currency = 'USD'): array
    {
        $this->ensureTables();
        $rows = $this->db->select(
            "SELECT * FROM {$this->monthlyTable}
             WHERE `year_month` >= :s AND `year_month` <= :e AND currency = :c
             ORDER BY `year_month` ASC",
            ['s' => $startYearMonth, 'e' => $endYearMonth, 'c' => strtoupper($currency)]
        );

        return array_map(fn(array $r) => MonthlyAggregation::fromArray($r), $rows);
    }

    private function validateDateFormat(string $date): void
    {
        $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new ValidationException(['date' => ['Date must follow YYYY-MM-DD format.']], 'Date must follow YYYY-MM-DD format.');
        }
    }

    /** YEAR_MONTH is a MySQL interval keyword; quote identifiers while keeping parameter names plain. */
    private function insertMonthly(array $data): void
    {
        $columns = array_keys($data);
        $this->db->statement('INSERT INTO ' . $this->monthlyTable . ' (' . implode(', ', array_map(static fn(string $column): string => "`{$column}`", $columns)) . ') VALUES (' . implode(', ', array_map(static fn(string $column): string => ':' . $column, $columns)) . ')', $data);
    }

    private function dailyCurrencyDivisor(string $currency): int
    {
        if (!preg_match('/^[A-Z]{3}$/D', $currency)) { throw new ValidationException(['currency' => ['Invalid currency code.']], 'Invalid currency code.'); }
        $row = $this->db->selectOne('SELECT code, decimal_places FROM currencies WHERE code = ?', [$currency]);
        if ($row === null) { throw new ValidationException(['currency' => ['Currency must exist in the installed registry.']], 'Unknown analytics currency.'); }
        $places = filter_var($row['decimal_places'], FILTER_VALIDATE_INT);
        if ($places === false || $places < 0 || $places > 2) { throw new LogicException('Analytics currency precision requires an explicit storage/metric upgrade.'); }
        return 10 ** $places;
    }

    private function dailyMinorTotal(array $rows, string $field): int
    {
        $total = 0;
        foreach ($rows as $row) {
            $value = filter_var($row[$field], FILTER_VALIDATE_INT);
            if ($value === false || $value < 0 || $value > PHP_INT_MAX - $total) {
                throw new LogicException('Invalid or overflowing canonical analytics amount requires explicit reconciliation.');
            }
            $total += $value;
        }
        return $total;
    }
}
