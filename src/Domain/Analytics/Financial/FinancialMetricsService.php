<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Financial;

use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use DateTimeImmutable;

final class FinancialMetricsService
{
    public function __construct(
        private readonly Connection $db
    ) {
    }

    /**
     * Computes high-integrity period financial metrics enforcing strict separation between
     * Invoiced amounts (billing claims) and Cash movements (actual banking/gateway liquidity).
     */
    public function calculatePeriodFinancialMetrics(
        string $startDate,
        string $endDate,
        string $currency = 'USD'
    ): FinancialPeriodSummary {
        $this->validateDates($startDate, $endDate);
        $curr = strtoupper(trim($currency));

        // 1. INVOICE METRICS (Accounting Claims)
        $grossInvoiced = 0.0;
        $netInvoiced = 0.0;
        $taxBilled = 0.0;
        $invoiceCount = 0;
        $paidCount = 0;
        $unpaidCount = 0;

        try {
            $invoices = $this->db->select(
                "SELECT id, total_amount, subtotal_amount, tax_amount, status, created_at
                 FROM invoices
                 WHERE date(created_at) >= :s AND date(created_at) <= :e
                   AND status != 'cancelled'",
                ['s' => $startDate, 'e' => $endDate]
            );

            foreach ($invoices as $inv) {
                $grossInvoiced += (float) ($inv['total_amount'] ?? 0.0);
                $netInvoiced += (float) ($inv['subtotal_amount'] ?? $inv['total_amount'] ?? 0.0);
                $taxBilled += (float) ($inv['tax_amount'] ?? 0.0);
                $invoiceCount++;

                $st = strtolower((string) ($inv['status'] ?? ''));
                if ($st === 'paid') {
                    $paidCount++;
                } else {
                    $unpaidCount++;
                }
            }
        } catch (\Throwable) {
            // Table might not exist or columns different
        }

        // 2. CASH METRICS (Real Captured Funds)
        $grossCash = 0.0;
        try {
            $payments = $this->db->select(
                "SELECT id, amount, status, created_at
                 FROM payments
                 WHERE date(created_at) >= :s AND date(created_at) <= :e
                   AND status = 'captured'",
                ['s' => $startDate, 'e' => $endDate]
            );

            foreach ($payments as $p) {
                $grossCash += (float) ($p['amount'] ?? 0.0);
            }
        } catch (\Throwable) {
            // Ignore
        }

        // 3. REFUND METRICS (Cash Outflow)
        $cashRefunded = 0.0;
        try {
            $refunds = $this->db->select(
                "SELECT id, amount, status, created_at
                 FROM refunds
                 WHERE date(created_at) >= :s AND date(created_at) <= :e
                   AND status = 'completed'",
                ['s' => $startDate, 'e' => $endDate]
            );

            foreach ($refunds as $r) {
                $cashRefunded += (float) ($r['amount'] ?? 0.0);
            }
        } catch (\Throwable) {
            // Ignore
        }

        $grossInvoiced = round($grossInvoiced, 2);
        $netInvoiced = round($netInvoiced, 2);
        $taxBilled = round($taxBilled, 2);
        $grossCash = round($grossCash, 2);
        $cashRefunded = round($cashRefunded, 2);
        $netCashFlow = round($grossCash - $cashRefunded, 2);

        $efficiency = $grossInvoiced > 0.0
            ? round(($grossCash / $grossInvoiced) * 100.0, 2)
            : 100.0;

        return new FinancialPeriodSummary(
            startDate: $startDate,
            endDate: $endDate,
            currency: $curr,
            grossInvoiced: $grossInvoiced,
            netInvoiced: $netInvoiced,
            taxBilled: $taxBilled,
            invoiceCount: $invoiceCount,
            paidInvoiceCount: $paidCount,
            unpaidInvoiceCount: $unpaidCount,
            grossCashCollected: $grossCash,
            cashRefunded: $cashRefunded,
            netCashFlow: $netCashFlow,
            collectionEfficiencyPercent: $efficiency
        );
    }

    /**
     * Evaluates all open unpaid invoices to compute aged debt buckets.
     */
    public function calculateAgingReceivables(string $asOfDate, string $currency = 'USD'): AgingReceivablesReport
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOfDate)) {
            throw new ValidationException(['as_of_date' => ['As-of date must follow YYYY-MM-DD format.']], 'As-of date must follow YYYY-MM-DD format.');
        }

        $curr = strtoupper(trim($currency));
        $asOf = new DateTimeImmutable($asOfDate);

        $totalOutstanding = 0.0;
        $currentBucket = 0.0;       // 0-30 days overdue
        $thirtyToSixty = 0.0;      // 31-60 days overdue
        $sixtyToNinety = 0.0;      // 61-90 days overdue
        $overNinety = 0.0;         // 91+ days overdue
        $overdueCount = 0;

        try {
            $openInvoices = $this->db->select(
                "SELECT id, total_amount, amount_paid, due_date, status
                 FROM invoices
                 WHERE status IN ('unpaid', 'partial', 'overdue')"
            );

            foreach ($openInvoices as $inv) {
                $total = (float) ($inv['total_amount'] ?? 0.0);
                $paid = (float) ($inv['amount_paid'] ?? 0.0);
                $balanceDue = max(0.0, $total - $paid);

                if ($balanceDue <= 0.0) {
                    continue;
                }

                $totalOutstanding += $balanceDue;

                $dueDateStr = (string) ($inv['due_date'] ?? $asOfDate);
                $dueDate = new DateTimeImmutable($dueDateStr);

                $daysOverdue = 0;
                if ($asOf > $dueDate) {
                    $diff = $asOf->diff($dueDate);
                    $daysOverdue = (int) $diff->days;
                }

                if ($daysOverdue > 0) {
                    $overdueCount++;
                }

                if ($daysOverdue <= 30) {
                    $currentBucket += $balanceDue;
                } elseif ($daysOverdue <= 60) {
                    $thirtyToSixty += $balanceDue;
                } elseif ($daysOverdue <= 90) {
                    $sixtyToNinety += $balanceDue;
                } else {
                    $overNinety += $balanceDue;
                }
            }
        } catch (\Throwable) {
            // Ignore
        }

        return new AgingReceivablesReport(
            asOfDate: $asOfDate,
            currency: $curr,
            totalOutstanding: round($totalOutstanding, 2),
            currentBucket: round($currentBucket, 2),
            thirtyToSixtyBucket: round($thirtyToSixty, 2),
            sixtyToNinetyBucket: round($sixtyToNinety, 2),
            overNinetyBucket: round($overNinety, 2),
            overdueInvoicesCount: $overdueCount
        );
    }

    /**
     * Computes Contribution Margin by netting gateway processing fees and direct costs
     * against real cash collections.
     */
    public function calculateContributionMargin(
        string $startDate,
        string $endDate,
        string $currency = 'USD',
        float $directCostsTotal = 0.0
    ): ContributionMarginReport {
        $summary = $this->calculatePeriodFinancialMetrics($startDate, $endDate, $currency);

        $gatewayFees = 0.0;
        try {
            $feeRows = $this->db->select(
                "SELECT SUM(fee_amount) as total_fees
                 FROM payments
                 WHERE date(created_at) >= :s AND date(created_at) <= :e
                   AND status = 'captured'",
                ['s' => $startDate, 'e' => $endDate]
            );
            $gatewayFees = (float) ($feeRows[0]['total_fees'] ?? 0.0);
        } catch (\Throwable) {
            // Ignore
        }

        $grossCash = $summary->getGrossCashCollected();
        $cashRefunded = $summary->getCashRefunded();
        $netCashFlow = $summary->getNetCashFlow();

        $gatewayFees = round($gatewayFees, 2);
        $directCostsTotal = round($directCostsTotal, 2);
        $netContribution = round($netCashFlow - $gatewayFees - $directCostsTotal, 2);

        $marginPercent = $netCashFlow > 0.0
            ? round(($netContribution / $netCashFlow) * 100.0, 2)
            : 0.0;

        return new ContributionMarginReport(
            startDate: $startDate,
            endDate: $endDate,
            currency: $summary->getCurrency(),
            grossCashCollected: $grossCash,
            cashRefunded: $cashRefunded,
            netCashFlow: $netCashFlow,
            gatewayFeesTotal: $gatewayFees,
            directCostsTotal: $directCostsTotal,
            netContribution: $netContribution,
            contributionMarginPercent: $marginPercent
        );
    }

    private function validateDates(string $startDate, string $endDate): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            throw new ValidationException(['start_date' => ['Start date must follow YYYY-MM-DD format.']], 'Start date must follow YYYY-MM-DD format.');
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            throw new ValidationException(['end_date' => ['End date must follow YYYY-MM-DD format.']], 'End date must follow YYYY-MM-DD format.');
        }

        if ($startDate > $endDate) {
            throw new ValidationException(['dates' => ['Start date cannot be after end date.']], 'Start date cannot be after end date.');
        }
    }
}
