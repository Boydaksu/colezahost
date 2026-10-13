<?php
declare(strict_types=1);
namespace Coleza\Domain\Analytics\Financial;

use Coleza\Domain\Analytics\AnalyticsInput;
use Coleza\Foundation\Database\Connection;
use LogicException;
use Coleza\Domain\Analytics\AnalyticsTransaction;

/** One event/currency/minor-unit policy shared by live reports, rollups and reconciliation. */
final class FinancialSourceReader
{
    public function __construct(private readonly Connection $db) {}

    public function period(string $startDate, string $endDate, string $currency = 'USD'): array
    {
        AnalyticsInput::range($startDate, $endDate);
        return AnalyticsTransaction::run($this->db, fn(): array => $this->readPeriod($startDate, $endDate, $currency));
    }

    private function readPeriod(string $startDate, string $endDate, string $currency): array
    {
        [$start, $end] = AnalyticsInput::range($startDate, $endDate);
        [$code, $places, $divisor] = AnalyticsInput::currency($this->db, $currency);
        if ($this->db->selectOne("SELECT id FROM invoices WHERE currency_code = ? AND status IN ('unpaid', 'paid', 'partially_paid', 'refunded') AND (issue_date IS NULL OR TRIM(issue_date) = '') LIMIT 1", [$code]) !== null) {
            throw new LogicException('Issued invoice without event date requires explicit reconciliation before analytics.');
        }
        $issued = []; $paid = 0;
        foreach ($this->db->select('SELECT total_minor, subtotal_minor, tax_total_minor, status FROM invoices WHERE issue_date >= :s AND issue_date <= :e AND currency_code = :c', ['s' => $startDate, 'e' => $endDate, 'c' => $code]) as $row) {
            if (in_array($row['status'], ['draft', 'cancelled'], true)) { continue; }
            if (!in_array($row['status'], ['unpaid', 'paid', 'partially_paid', 'refunded'], true)) { throw new LogicException('Unknown invoice state requires explicit reconciliation before analytics.'); }
            $issued[] = $row; if (in_array($row['status'], ['paid', 'refunded'], true)) { $paid++; }
        }
        if ($this->db->selectOne("SELECT id FROM payments WHERE currency_code = ? AND status IN ('completed', 'partially_refunded', 'refunded') AND paid_at IS NULL LIMIT 1", [$code]) !== null) {
            throw new LogicException('Settled payment without event date requires explicit reconciliation before analytics.');
        }
        if ($this->db->selectOne('SELECT id FROM refunds WHERE currency_code = ? AND refunded_at IS NULL LIMIT 1', [$code]) !== null) {
            throw new LogicException('Refund without event date requires explicit reconciliation before analytics.');
        }
        $bindings = ['c' => $code, 's' => $start, 'e' => $end]; $settled = [];
        foreach ($this->db->select('SELECT amount_minor, fee_minor, status FROM payments WHERE currency_code = :c AND paid_at >= :s AND paid_at < :e', $bindings) as $row) {
            if (in_array($row['status'], ['pending', 'failed'], true)) { continue; }
            if (!in_array($row['status'], ['completed', 'partially_refunded', 'refunded'], true)) { throw new LogicException('Unknown payment state requires explicit reconciliation before analytics.'); }
            $settled[] = $row;
        }
        $refunds = $this->db->select('SELECT amount_minor FROM refunds WHERE currency_code = :c AND refunded_at >= :s AND refunded_at < :e', $bindings);
        $values = ['gross_invoiced' => AnalyticsInput::total($issued, 'total_minor'), 'net_invoiced' => AnalyticsInput::total($issued, 'subtotal_minor'), 'tax_billed' => AnalyticsInput::total($issued, 'tax_total_minor'),
            'cash_collected' => AnalyticsInput::total($settled, 'amount_minor'), 'cash_refunded' => AnalyticsInput::total($refunds, 'amount_minor'), 'gateway_fees' => AnalyticsInput::total($settled, 'fee_minor')];
        foreach ($values as $field => $minor) { $values[$field] = round($minor / $divisor, 2); }
        return $values + ['currency' => $code, 'currency_decimal_places' => $places, 'invoices_count' => count($issued), 'paid_invoices_count' => $paid, 'unpaid_invoices_count' => count($issued) - $paid,
            'net_cash_flow' => round($values['cash_collected'] - $values['cash_refunded'], 2)];
    }

    /** Current open balances aged at the requested date; this is not historical balance reconstruction. */
    public function openInvoices(string $asOfDate, string $currency): array
    {
        AnalyticsInput::date($asOfDate, 'as_of_date'); [$code] = AnalyticsInput::currency($this->db, $currency);
        $rows = $this->db->select("SELECT id, total_minor, paid_amount_minor, due_date, issue_date, status FROM invoices WHERE currency_code = ? AND status IN ('unpaid', 'partially_paid')", [$code]);
        $open = [];
        foreach ($rows as $row) {
            AnalyticsInput::date((string) $row['issue_date']); AnalyticsInput::date((string) $row['due_date']);
            $total = AnalyticsInput::minor($row['total_minor']); $paid = AnalyticsInput::minor($row['paid_amount_minor']);
            if ($paid > $total) { throw new LogicException('Invoice balance requires explicit reconciliation.'); }
            if ($row['issue_date'] <= $asOfDate && $total > $paid) { $row['balance_minor'] = $total - $paid; $open[] = $row; }
        }
        return $open;
    }
}
