<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\ReadModels;

use Coleza\Domain\Identity\Audit\AuditLogger;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Database\PdoSchema;
use Coleza\Domain\Analytics\AnalyticsSchema;
use Coleza\Foundation\Exceptions\ValidationException;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use LogicException;
use Coleza\Domain\Analytics\AnalyticsInput;
use Coleza\Domain\Analytics\AnalyticsTransaction;
use Coleza\Domain\Analytics\Financial\FinancialSourceReader;

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
        $this->validateDateFormat($date);
        $this->dailyCurrencyDivisor(strtoupper(trim($currency)));
        $this->assertReadSchema();
        return AnalyticsTransaction::run($this->db, fn(): DailyAggregation => $this->calculateDay($date, $currency));
    }

    private function calculateDay(string $date, string $currency): DailyAggregation
    {
        AnalyticsSchema::assertCaller($this->db);
        $this->validateDateFormat($date);
        $curr = strtoupper(trim($currency));

        $divisor = $this->dailyCurrencyDivisor($curr);
        $startAt = $date . ' 00:00:00';
        $endAt = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        $bindings = ['currency' => $curr, 'start' => $startAt, 'end' => $endAt];

        $financial = (new FinancialSourceReader($this->db))->period($date, $date, $curr);
        $grossInvoiced = $financial['gross_invoiced'];
        $netInvoiced = $financial['net_invoiced'];
        $taxBilled = $financial['tax_billed'];
        $invoicesCount = $financial['invoices_count'];
        $cashCollected = $financial['cash_collected'];
        $gatewayFees = $financial['gateway_fees'];
        $cashRefunded = $financial['cash_refunded'];
        $netCashFlow = $financial['net_cash_flow'];

        // All source reads must succeed before replacing a daily record. User/ticket counters remain global.
        $newOrdersCount = (int) $this->db->selectOne('SELECT COUNT(*) AS n FROM orders WHERE currency_code = :currency AND created_at >= :start AND created_at < :end', $bindings)['n'];
        $timeBindings = ['start' => $startAt, 'end' => $endAt];
        $newCustomersCount = (int) $this->db->selectOne('SELECT COUNT(*) AS n FROM users WHERE created_at >= :start AND created_at < :end', $timeBindings)['n'];
        $ticketsOpened = (int) $this->db->selectOne('SELECT COUNT(*) AS n FROM support_tickets WHERE created_at >= :start AND created_at < :end', $timeBindings)['n'];
        $ticketsResolved = (int) $this->db->selectOne("SELECT COUNT(*) AS n FROM support_tickets WHERE updated_at >= :start AND updated_at < :end AND status = 'resolved'", $timeBindings)['n'];
        $this->ensureTables();
        $now = date('Y-m-d H:i:s');

        DailyAggregationEvidence::assertInstalled($this->db);
        $data = [
            'date' => $date, 'currency' => $curr,
            'gross_invoiced' => $grossInvoiced, 'net_invoiced' => $netInvoiced, 'tax_billed' => $taxBilled,
            'invoices_count' => $invoicesCount, 'cash_collected' => $cashCollected, 'cash_refunded' => $cashRefunded,
            'net_cash_flow' => $netCashFlow, 'gateway_fees' => $gatewayFees,
            'new_orders_count' => $newOrdersCount, 'new_customers_count' => $newCustomersCount,
            'tickets_opened_count' => $ticketsOpened, 'tickets_resolved_count' => $ticketsResolved, 'rebuilt_at' => $now,
        ];
        $places = match ($divisor) { 1 => 0, 10 => 1, 100 => 2 };
        $data['calculation_evidence'] = DailyAggregationEvidence::create($data, $places);
        $id = $this->upsert($this->dailyTable, $data, 'date');

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
        $parsed = preg_match('/^\d{4}-\d{2}$/D', $yearMonth) ? DateTimeImmutable::createFromFormat('!Y-m-d', $yearMonth . '-01') : false;
        if ($parsed === false || $parsed->format('Y-m') !== $yearMonth) { throw new ValidationException(['year_month' => ['Month format must be YYYY-MM.']], 'Month format must be YYYY-MM.'); }
        $this->dailyCurrencyDivisor(strtoupper(trim($currency)));
        $this->assertReadSchema();
        return AnalyticsTransaction::run($this->db, function () use ($yearMonth, $currency): MonthlyAggregation {
            $data = $this->deriveMonth($yearMonth, $currency);
            $data['rebuilt_at'] = date('Y-m-d H:i:s');
            $data['id'] = $this->upsert($this->monthlyTable, $data, 'year_month');
            return MonthlyAggregation::fromArray($data);
        });
    }

    /** Pure verification path: never creates tables or replaces stored rows. */
    private function deriveMonth(string $yearMonth, string $currency): array
    {
        AnalyticsSchema::assertCaller($this->db);
        $parsed = preg_match('/^\d{4}-\d{2}$/D', $yearMonth) ? DateTimeImmutable::createFromFormat('!Y-m-d', $yearMonth . '-01') : false;
        if ($parsed === false || $parsed->format('Y-m') !== $yearMonth) {
            throw new ValidationException(['year_month' => ['Month format must be YYYY-MM.']], 'Month format must be YYYY-MM.');
        }
        $curr = strtoupper(trim($currency));
        $divisor = $this->dailyCurrencyDivisor($curr);
        $places = match ($divisor) { 1 => 0, 10 => 1, 100 => 2 };
        $startDate = $parsed->format('Y-m-d');
        $endDate = $parsed->format('Y-m-t');

        // A missing day is unknown, including on otherwise empty months. Do not manufacture zero.
        DailyAggregationEvidence::assertInstalled($this->db);
        $dailyRows = $this->db->select(
            "SELECT * FROM {$this->dailyTable} WHERE date >= :s AND date <= :e AND currency = :c ORDER BY date",
            ['s' => $startDate, 'e' => $endDate, 'c' => $curr]
        );
        if (count($dailyRows) !== (int) $parsed->format('t')) {
            throw new LogicException('Complete daily coverage is required before building a monthly aggregation.');
        }
        $expected = $parsed;
        $totals = array_fill_keys(['gross_invoiced', 'net_invoiced', 'tax_billed', 'cash_collected', 'cash_refunded', 'gateway_fees'], 0);
        foreach ($dailyRows as $day) {
            if ($day['date'] !== $expected->format('Y-m-d')) {
                throw new LogicException('Complete daily coverage is required before building a monthly aggregation.');
            }
            DailyAggregationEvidence::verify($day, $places);
            foreach ($totals as $field => $total) {
                $amount = $this->monthlyStorageCents($day[$field]);
                if ($amount > 999999999999 - $total) { throw new LogicException('Monthly analytics amount exceeds storage capacity; explicit upgrade required.'); }
                $totals[$field] = $total + $amount;
            }
            $expected = $expected->modify('+1 day');
        }
        $grossInvoiced = $totals['gross_invoiced'] / 100;
        $netInvoiced = $totals['net_invoiced'] / 100;
        $taxBilled = $totals['tax_billed'] / 100;
        $cashCollected = $totals['cash_collected'] / 100;
        $cashRefunded = $totals['cash_refunded'] / 100;
        $gatewayFees = $totals['gateway_fees'] / 100;
        $netCashFlow = round($cashCollected - $cashRefunded, 2);

        // Source failures propagate. A previous day/month's snapshot is not a month-end balance.
        $snap = $this->db->selectOne(
            "SELECT total_mrr, total_arr, metadata FROM analytics_subscription_snapshots
             WHERE snapshot_date = :e AND currency = :c",
            ['e' => $endDate, 'c' => $curr]
        );
        if ($snap === null) { throw new LogicException('Canonical month-end subscription snapshot is required before building a monthly aggregation.'); }
        $metadata = json_decode((string) ($snap['metadata'] ?? ''), true);
        if (!is_array($metadata) || ($metadata['source'] ?? null) !== 'services:canonical_minor_units' || ($metadata['currency_decimal_places'] ?? null) !== $places) {
            throw new LogicException('Month-end subscription source or currency precision requires explicit reconciliation.');
        }
        $endMrr = $this->monthlyStorageCents($snap['total_mrr']) / 100;
        $endArr = $this->monthlyStorageCents($snap['total_arr']) / 100;
        // Subscription movement counts still require a separate event source; existing contract retained.
        $newSubs = 0;
        $churnedSubs = 0;
        return [
            'year_month' => $yearMonth, 'currency' => $curr,
            'gross_invoiced' => $grossInvoiced, 'net_invoiced' => $netInvoiced, 'tax_billed' => $taxBilled,
            'cash_collected' => $cashCollected, 'cash_refunded' => $cashRefunded,
            'net_cash_flow' => $netCashFlow, 'gateway_fees' => $gatewayFees,
            'end_of_month_mrr' => $endMrr, 'end_of_month_arr' => $endArr,
            'new_subscriptions_count' => $newSubs, 'churned_subscriptions_count' => $churnedSubs,
        ];
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

        $this->assertReadSchema();
        [$currency] = AnalyticsInput::currency($this->db, $currency);
        if ($this->auditLogger !== null && !PdoSchema::useInstalledSchema($this->db, ['security_audit_logs' => ['id', 'actor_user_id', 'impersonator_user_id', 'event_type', 'target_resource', 'ip_address', 'user_agent', 'payload', 'created_at']])) {
            throw new LogicException('Install audit schema before analytics rebuild.');
        }
        return AnalyticsTransaction::run($this->db, function () use ($startDate, $endDate, $currency): RebuildExecutionReport {
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
            $rebuiltRows = $this->db->select('SELECT date, currency, calculation_evidence FROM analytics_daily_aggregations WHERE date >= ? AND date <= ? AND currency = ? ORDER BY date', [$startDate, $endDate, $currency]);
            $checksum = hash('sha256', json_encode($rebuiltRows, JSON_THROW_ON_ERROR));

            $this->auditLogger?->log(
                actorUserId: 0,
                eventType: 'ANALYTICS_READ_MODELS_REBUILT',
                targetResource: 'analytics:daily_rebuild',
                payload: [
                    'scope' => 'daily',
                    'currency' => $currency,
                    'checksum' => $checksum,
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
        });
    }

    /**
     * Retrieves pre-aggregated daily series for dashboard charts.
     *
     * @return array<DailyAggregation>
     */
    public function getDailySeries(string $startDate, string $endDate, string $currency = 'USD'): array
    {
        $this->assertReadSchema();
        AnalyticsInput::range($startDate, $endDate);
        [$currency, $places] = AnalyticsInput::currency($this->db, $currency);
        $rows = $this->db->select(
            "SELECT * FROM {$this->dailyTable}
             WHERE date >= :s AND date <= :e AND currency = :c
             ORDER BY date ASC",
            ['s' => $startDate, 'e' => $endDate, 'c' => strtoupper($currency)]
        );

        foreach ($rows as $row) { DailyAggregationEvidence::verify($row, $places); }
        return array_map(fn(array $r) => DailyAggregation::fromArray($r), $rows);
    }

    /**
     * Retrieves pre-aggregated monthly series for high-level management reports.
     *
     * @return array<MonthlyAggregation>
     */
    public function getMonthlySeries(string $startYearMonth, string $endYearMonth, string $currency = 'USD'): array
    {
        $this->assertReadSchema();
        AnalyticsInput::range($startYearMonth . '-01', $endYearMonth . '-01');
        [$currency] = AnalyticsInput::currency($this->db, $currency);
        $rows = $this->db->select(
            "SELECT * FROM {$this->monthlyTable}
             WHERE `year_month` >= :s AND `year_month` <= :e AND currency = :c
             ORDER BY `year_month` ASC",
            ['s' => $startYearMonth, 'e' => $endYearMonth, 'c' => strtoupper($currency)]
        );

        foreach ($rows as $row) {
            $expected = $this->deriveMonth($row['year_month'], $currency);
            foreach ($expected as $field => $value) {
                $stored = $row[$field];
                if (is_numeric($value) && (!is_numeric($stored) || !is_finite((float) $stored))) { throw new LogicException('Invalid stored monthly amount; rebuild required.'); }
                if ((string) $stored !== (string) $value && (!is_numeric($value) || (float) $stored !== (float) $value)) {
                    throw new LogicException('Stored monthly aggregation differs from verified inputs; rebuild required.');
                }
            }
        }
        return array_map(fn(array $r) => MonthlyAggregation::fromArray($r), $rows);
    }

    private function validateDateFormat(string $date): void
    {
        $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new ValidationException(['date' => ['Date must follow YYYY-MM-DD format.']], 'Date must follow YYYY-MM-DD format.');
        }
    }

    private function assertReadSchema(): void
    {
        AnalyticsSchema::assertCaller($this->db);
        if (!PdoSchema::useInstalledSchema($this->db, self::schemaRequirements())) { throw new LogicException('Installed analytics schema required; explicit installation needed.'); }
        AnalyticsSchema::ensureIndexes($this->db, self::indexRequirements(), false);
        DailyAggregationEvidence::assertInstalled($this->db);
    }

    /** Unique period/currency key arbitrates concurrent inserts, including evidence in one write. */
    private function upsert(string $table, array $data, string $period): int
    {
        $columns = array_keys($data);
        $quoted = array_map(static fn(string $column): string => "`{$column}`", $columns);
        $mutable = array_diff($columns, [$period, 'currency']);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $quoted) . ') VALUES (' . implode(', ', array_map(static fn(string $column): string => ':' . $column, $columns)) . ')';
        if ($this->db->getDriverName() === 'sqlite') {
            $sql .= " ON CONFLICT (`{$period}`, currency) DO UPDATE SET " . implode(', ', array_map(static fn(string $column): string => "`{$column}` = excluded.`{$column}`", $mutable)) . ' RETURNING id';
            return (int) $this->db->selectOne($sql, $data)['id'];
        }
        $sql .= ' ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), ' . implode(', ', array_map(static fn(string $column): string => "`{$column}` = VALUES(`{$column}`)", $mutable));
        $this->db->statement($sql, $data);
        return (int) $this->db->getPdo()->lastInsertId();
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

    /** Aggregates are stored at two decimal places, independently of operational minor-unit scale. */
    private function monthlyStorageCents(mixed $value): int
    {
        if ((!is_int($value) && !is_float($value) && !is_string($value)) || !preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/D', (string) $value, $parts)) {
            throw new LogicException('Invalid monthly analytics amount requires explicit reconciliation.');
        }
        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }

}
