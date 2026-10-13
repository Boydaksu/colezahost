<?php
declare(strict_types=1);
namespace Coleza\Domain\Analytics\Context;
use Coleza\Domain\Analytics\AnalyticsInput;
use Coleza\Domain\Analytics\Financial\FinancialSourceReader;
use Coleza\Domain\Analytics\ReadModels\DailyAggregationEvidence;
use Coleza\Foundation\Database\Connection;
use DateTimeImmutable;
use LogicException;
use PDOException;

final class AnalyticsFreshnessService
{
    public function __construct(private readonly Connection $db, private readonly int $staleThresholdSeconds = 900)
    {
        if ($staleThresholdSeconds < 60) { throw new \InvalidArgumentException('Freshness threshold must be at least 60 seconds.'); }
    }

    /** Age is reported separately from financial-source consistency; absent evidence is unknown. */
    public function evaluateFreshness(string $currency = 'USD'): DataFreshnessMetadata
    {
        $now = new DateTimeImmutable(); $nowStr = $now->format('Y-m-d H:i:s');
        $unknown = static fn(string $notice) => new DataFreshnessMetadata($nowStr, null, null, DataFreshnessStatus::UNKNOWN, false, $notice);
        try {
            [$code, $places] = AnalyticsInput::currency($this->db, $currency);
            DailyAggregationEvidence::assertInstalled($this->db);
            $rows = $this->db->select('SELECT * FROM analytics_daily_aggregations WHERE currency = ? ORDER BY rebuilt_at DESC', [$code]);
            if ($rows === []) { return $unknown('No verified aggregation is available for this currency.'); }
            $last = null; $consistent = true;
            foreach ($rows as $row) {
                DailyAggregationEvidence::verify($row, $places);
                $timestamp = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) $row['rebuilt_at']);
                if ($timestamp === false || $timestamp->format('Y-m-d H:i:s') !== $row['rebuilt_at'] || $timestamp > $now || $row['date'] > $now->format('Y-m-d')) {
                    return $unknown('Aggregation timestamp cannot establish freshness.');
                }
                $last ??= $timestamp;
                $current = (new FinancialSourceReader($this->db))->period($row['date'], $row['date'], $code);
                foreach (['gross_invoiced', 'net_invoiced', 'tax_billed', 'cash_collected', 'cash_refunded', 'net_cash_flow', 'gateway_fees', 'invoices_count'] as $field) {
                    if ((float) $row[$field] !== (float) $current[$field]) { $consistent = false; }
                }
            }
            $firstDate = min(array_column($rows, 'date'));
            $today = $now->format('Y-m-d');
            foreach ([['invoices', 'issue_date', "status IN ('unpaid', 'paid', 'partially_paid', 'refunded')"], ['payments', 'substr(paid_at, 1, 10)', "status IN ('completed', 'partially_refunded', 'refunded')"], ['refunds', 'substr(refunded_at, 1, 10)', '1 = 1']] as [$table, $event, $statusFilter]) {
                $missing = $this->db->selectOne("SELECT 1 FROM {$table} WHERE currency_code = :c AND {$statusFilter} AND {$event} >= :first AND {$event} <= :today AND {$event} NOT IN (SELECT date FROM analytics_daily_aggregations WHERE currency = :aggcurrency) LIMIT 1", ['c' => $code, 'first' => $firstDate, 'today' => $today, 'aggcurrency' => $code]);
                if ($missing !== null) { $consistent = false; }
            }
            $lag = $now->getTimestamp() - $last->getTimestamp();
            $status = match (true) {
                !$consistent => DataFreshnessStatus::STALE,
                $lag <= 60 => DataFreshnessStatus::REALTIME,
                $lag <= $this->staleThresholdSeconds => DataFreshnessStatus::NEAR_REALTIME,
                default => DataFreshnessStatus::STALE,
            };
            return new DataFreshnessMetadata($nowStr, $last->format('Y-m-d H:i:s'), $lag, $status, $consistent,
                $consistent ? 'Verified financial rollups; age does not certify subscription, support or provider data.' : 'Financial source data differs from stored aggregation; rebuild required.');
        } catch (LogicException|PDOException) {
            return $unknown('Aggregation or financial source evidence is unavailable; freshness cannot be established.');
        }
    }
}
