<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\ReadModels;

use Coleza\Domain\Audit\AuditLogger;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;

final class ReadModelAggregationService
{
    private string $dailyTable = 'analytics_daily_aggregations';
    private string $monthlyTable = 'analytics_monthly_aggregations';

    public function __construct(
        private readonly Connection $db,
        private readonly ?AuditLogger $auditLogger = null
    ) {
    }

    public function ensureTables(): void
    {
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
        $this->db->statement($sqlDaily);

        $sqlMonthly = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                year_month VARCHAR(7) NOT NULL,
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
        $this->db->statement($sqlMonthly);

        if ($driver !== 'sqlite') {
            try {
                $this->db->statement("CREATE UNIQUE INDEX uq_daily_agg ON {$this->dailyTable} (date, currency)");
                $this->db->statement("CREATE UNIQUE INDEX uq_monthly_agg ON {$this->monthlyTable} (year_month, currency)");
            } catch (\Throwable) {
                // Ignore
            }
        }
    }

    /**
     * Computes and persists daily read model aggregation for a single date.
     */
    public function aggregateDay(string $date, string $currency = 'USD'): DailyAggregation
    {
        $this->ensureTables();
        $this->validateDateFormat($date);
        $curr = strtoupper(trim($currency));

        // 1. Invoices
        $grossInvoiced = 0.0;
        $netInvoiced = 0.0;
        $taxBilled = 0.0;
        $invoicesCount = 0;
        try {
            $invoices = $this->db->select(
                "SELECT total_amount, subtotal_amount, tax_amount FROM invoices
                 WHERE date(created_at) = :d AND status != 'cancelled'",
                ['d' => $date]
            );
            foreach ($invoices as $inv) {
                $grossInvoiced += (float) ($inv['total_amount'] ?? 0.0);
                $netInvoiced += (float) ($inv['subtotal_amount'] ?? $inv['total_amount'] ?? 0.0);
                $taxBilled += (float) ($inv['tax_amount'] ?? 0.0);
                $invoicesCount++;
            }
        } catch (\Throwable) {
            // Ignore
        }

        // 2. Payments & Gateway Fees
        $cashCollected = 0.0;
        $gatewayFees = 0.0;
        try {
            $payments = $this->db->select(
                "SELECT amount, fee_amount FROM payments
                 WHERE date(created_at) = :d AND status = 'captured'",
                ['d' => $date]
            );
            foreach ($payments as $p) {
                $cashCollected += (float) ($p['amount'] ?? 0.0);
                $gatewayFees += (float) ($p['fee_amount'] ?? 0.0);
            }
        } catch (\Throwable) {
            // Ignore
        }

        // 3. Refunds
        $cashRefunded = 0.0;
        try {
            $refunds = $this->db->select(
                "SELECT amount FROM refunds
                 WHERE date(created_at) = :d AND status = 'completed'",
                ['d' => $date]
            );
            foreach ($refunds as $r) {
                $cashRefunded += (float) ($r['amount'] ?? 0.0);
            }
        } catch (\Throwable) {
            // Ignore
        }

        // 4. Orders
        $newOrdersCount = 0;
        try {
            $orders = $this->db->select("SELECT id FROM orders WHERE date(created_at) = :d", ['d' => $date]);
            $newOrdersCount = count($orders);
        } catch (\Throwable) {
            // Ignore
        }

        // 5. Customers
        $newCustomersCount = 0;
        try {
            $customers = $this->db->select("SELECT id FROM users WHERE date(created_at) = :d", ['d' => $date]);
            $newCustomersCount = count($customers);
        } catch (\Throwable) {
            // Ignore
        }

        // 6. Tickets
        $ticketsOpened = 0;
        $ticketsResolved = 0;
        try {
            $opened = $this->db->select("SELECT id FROM support_tickets WHERE date(created_at) = :d", ['d' => $date]);
            $ticketsOpened = count($opened);
            $resolved = $this->db->select("SELECT id FROM support_tickets WHERE date(updated_at) = :d AND status = 'resolved'", ['d' => $date]);
            $ticketsResolved = count($resolved);
        } catch (\Throwable) {
            // Ignore
        }

        $grossInvoiced = round($grossInvoiced, 2);
        $netInvoiced = round($netInvoiced, 2);
        $taxBilled = round($taxBilled, 2);
        $cashCollected = round($cashCollected, 2);
        $cashRefunded = round($cashRefunded, 2);
        $netCashFlow = round($cashCollected - $cashRefunded, 2);
        $gatewayFees = round($gatewayFees, 2);
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
            "SELECT id FROM {$this->monthlyTable} WHERE year_month = :ym AND currency = :c LIMIT 1",
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
            $this->db->insert($this->monthlyTable, [
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
                "SELECT id FROM {$this->monthlyTable} WHERE year_month = :ym AND currency = :c ORDER BY id DESC LIMIT 1",
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
             WHERE year_month >= :s AND year_month <= :e AND currency = :c
             ORDER BY year_month ASC",
            ['s' => $startYearMonth, 'e' => $endYearMonth, 'c' => strtoupper($currency)]
        );

        return array_map(fn(array $r) => MonthlyAggregation::fromArray($r), $rows);
    }

    private function validateDateFormat(string $date): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new ValidationException(['date' => ['Date must follow YYYY-MM-DD format.']], 'Date must follow YYYY-MM-DD format.');
        }
    }
}
