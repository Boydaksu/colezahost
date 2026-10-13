<?php
declare(strict_types=1);
namespace Coleza\Domain\Analytics\Financial;
use Coleza\Domain\Analytics\AnalyticsInput;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use DateTimeImmutable;

final class FinancialMetricsService
{
    public function __construct(private readonly Connection $db) {}

    public function calculatePeriodFinancialMetrics(string $startDate, string $endDate, string $currency = 'USD'): FinancialPeriodSummary
    {
        $v = (new FinancialSourceReader($this->db))->period($startDate, $endDate, $currency);
        return new FinancialPeriodSummary($startDate, $endDate, $v['currency'], $v['gross_invoiced'], $v['net_invoiced'], $v['tax_billed'],
            $v['invoices_count'], $v['paid_invoices_count'], $v['unpaid_invoices_count'], $v['cash_collected'], $v['cash_refunded'], $v['net_cash_flow'],
            $v['gross_invoiced'] > 0 ? round($v['cash_collected'] / $v['gross_invoiced'] * 100, 2) : 100.0);
    }

    /** Current balances aged at the requested date; historical balances require a separate event ledger. */
    public function calculateAgingReceivables(string $asOfDate, string $currency = 'USD'): AgingReceivablesReport
    {
        [$code, , $divisor] = AnalyticsInput::currency($this->db, $currency);
        $rows = (new FinancialSourceReader($this->db))->openInvoices($asOfDate, $code);
        $buckets = [0, 0, 0, 0]; $overdue = 0;
        $asOf = new DateTimeImmutable($asOfDate);
        foreach ($rows as $row) {
            $due = new DateTimeImmutable($row['due_date']);
            $days = $asOf > $due ? (int) $asOf->diff($due)->days : 0;
            if ($days > 0) { $overdue++; }
            $index = $days <= 30 ? 0 : ($days <= 60 ? 1 : ($days <= 90 ? 2 : 3));
            $buckets[$index] = AnalyticsInput::total([['amount' => $buckets[$index]], ['amount' => $row['balance_minor']]], 'amount');
        }
        $total = AnalyticsInput::total(array_map(static fn($n) => ['amount' => $n], $buckets), 'amount');
        $amounts = array_map(static fn($n) => round($n / $divisor, 2), $buckets);
        return new AgingReceivablesReport($asOfDate, $code, round($total / $divisor, 2), ...[...$amounts, $overdue]);
    }

    public function calculateContributionMargin(string $startDate, string $endDate, string $currency = 'USD', float $directCostsTotal = 0.0): ContributionMarginReport
    {
        if (!is_finite($directCostsTotal) || $directCostsTotal < 0) { throw new ValidationException(['direct_costs' => ['Direct costs must be finite and non-negative.']], 'Invalid direct costs.'); }
        $v = (new FinancialSourceReader($this->db))->period($startDate, $endDate, $currency);
        $cost = round($directCostsTotal, 2);
        $contribution = round($v['net_cash_flow'] - $v['gateway_fees'] - $cost, 2);
        return new ContributionMarginReport($startDate, $endDate, $v['currency'], $v['cash_collected'], $v['cash_refunded'], $v['net_cash_flow'], $v['gateway_fees'], $cost, $contribution,
            $v['net_cash_flow'] > 0 ? round($contribution / $v['net_cash_flow'] * 100, 2) : 0.0);
    }
}
