<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Reconciliation;

use Coleza\Domain\Analytics\Financial\FinancialPeriodSummary;
use Coleza\Domain\Finance\Accounts\AccountTransaction;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use DateTimeImmutable;

final class LedgerReconciliationService
{
    public function __construct(
        private Connection $db
    ) {
    }

    /**
     * Reconcile Analytics aggregated data against Finance authoritative ledger for the given window.
     * Enforces the hard architectural invariant: Source-Ledger Reconciliation = Zero Unexplained Diff.
     */
    public function reconcile(string $startDate, string $endDate, string $currency = 'USD'): LedgerReconciliationReport
    {
        $this->validateDates($startDate, $endDate);
        $curr = strtoupper(trim($currency));

        // 1. Analytics Perspective (derived read model aggregations or payments table)
        $analyticsCashCollected = 0.0;
        $analyticsCashRefunded = 0.0;

        // Try reading from analytics_daily_aggregations first
        $aggData = $this->queryAnalyticsAggregations($startDate, $endDate, $curr);
        if ($aggData !== null) {
            $analyticsCashCollected = $aggData['cash_collected'];
            $analyticsCashRefunded = $aggData['refunds_issued'];
        } else {
            // Fallback to payments/refunds tables
            $paymentData = $this->queryTransactionalPayments($startDate, $endDate, $curr);
            $analyticsCashCollected = $paymentData['cash_collected'];
            $analyticsCashRefunded = $paymentData['refunds_issued'];
        }

        $analyticsNetCash = $analyticsCashCollected - $analyticsCashRefunded;

        // 2. Finance Ledger Perspective (authoritative immutable double-entry/account ledger)
        $ledgerData = $this->queryLedgerTransactions($startDate, $endDate, $curr);
        $ledgerPayments = $ledgerData['payments'];
        $ledgerRefunds = $ledgerData['refunds'];
        $ledgerNetCash = $ledgerPayments - $ledgerRefunds;

        // 3. Compute Variances
        $paymentDiff = round($analyticsCashCollected - $ledgerPayments, 2);
        $refundDiff = round($analyticsCashRefunded - $ledgerRefunds, 2);
        $netDiff = round($analyticsNetCash - $ledgerNetCash, 2);

        $discrepancies = [];
        if (abs($paymentDiff) > 0.001) {
            $discrepancies[] = sprintf(
                'Payment variance detected: Analytics shows %.2f %s, Ledger shows %.2f %s (Diff: %+.2f).',
                $analyticsCashCollected,
                $curr,
                $ledgerPayments,
                $curr,
                $paymentDiff
            );
        }

        if (abs($refundDiff) > 0.001) {
            $discrepancies[] = sprintf(
                'Refund variance detected: Analytics shows %.2f %s, Ledger shows %.2f %s (Diff: %+.2f).',
                $analyticsCashRefunded,
                $curr,
                $ledgerRefunds,
                $curr,
                $refundDiff
            );
        }

        if (abs($netDiff) > 0.001) {
            $discrepancies[] = sprintf(
                'Net cash movement variance detected: Analytics net %.2f %s vs Ledger net %.2f %s (Diff: %+.2f).',
                $analyticsNetCash,
                $curr,
                $ledgerNetCash,
                $curr,
                $netDiff
            );
        }

        $status = count($discrepancies) === 0 ? ReconciliationStatus::MATCHED : ReconciliationStatus::DISCREPANCY_DETECTED;

        return new LedgerReconciliationReport(
            startDate: $startDate,
            endDate: $endDate,
            currency: $curr,
            analyticsCashCollected: $analyticsCashCollected,
            ledgerPaymentsCollected: $ledgerPayments,
            paymentVariance: $paymentDiff,
            analyticsCashRefunded: $analyticsCashRefunded,
            ledgerRefundsPaid: $ledgerRefunds,
            refundVariance: $refundDiff,
            analyticsNetCash: $analyticsNetCash,
            ledgerNetCash: $ledgerNetCash,
            netCashVariance: $netDiff,
            status: $status,
            discrepancies: $discrepancies
        );
    }

    /**
     * Reconcile directly against an existing FinancialPeriodSummary object.
     */
    public function reconcileFinancialPeriodSummary(
        FinancialPeriodSummary $summary,
        string $startDate,
        string $endDate,
        string $currency = 'USD'
    ): LedgerReconciliationReport {
        $curr = strtoupper(trim($currency));
        $analyticsCashCollected = $summary->getGrossCashCollected();
        $analyticsCashRefunded = $summary->getCashRefunded();
        $analyticsNetCash = $summary->getNetCashFlow();

        $ledgerData = $this->queryLedgerTransactions($startDate, $endDate, $curr);
        $ledgerPayments = $ledgerData['payments'];
        $ledgerRefunds = $ledgerData['refunds'];
        $ledgerNetCash = $ledgerPayments - $ledgerRefunds;

        $paymentDiff = round($analyticsCashCollected - $ledgerPayments, 2);
        $refundDiff = round($analyticsCashRefunded - $ledgerRefunds, 2);
        $netDiff = round($analyticsNetCash - $ledgerNetCash, 2);

        $discrepancies = [];
        if (abs($paymentDiff) > 0.001) {
            $discrepancies[] = sprintf(
                'Payment variance detected: Analytics summary %.2f %s vs Ledger %.2f %s (Diff: %+.2f).',
                $analyticsCashCollected,
                $curr,
                $ledgerPayments,
                $curr,
                $paymentDiff
            );
        }

        if (abs($refundDiff) > 0.001) {
            $discrepancies[] = sprintf(
                'Refund variance detected: Analytics summary %.2f %s vs Ledger %.2f %s (Diff: %+.2f).',
                $analyticsCashRefunded,
                $curr,
                $ledgerRefunds,
                $curr,
                $refundDiff
            );
        }

        if (abs($netDiff) > 0.001) {
            $discrepancies[] = sprintf(
                'Net cash variance detected: Analytics summary net %.2f %s vs Ledger net %.2f %s (Diff: %+.2f).',
                $analyticsNetCash,
                $curr,
                $ledgerNetCash,
                $curr,
                $netDiff
            );
        }

        $status = count($discrepancies) === 0 ? ReconciliationStatus::MATCHED : ReconciliationStatus::DISCREPANCY_DETECTED;

        return new LedgerReconciliationReport(
            startDate: $startDate,
            endDate: $endDate,
            currency: $curr,
            analyticsCashCollected: $analyticsCashCollected,
            ledgerPaymentsCollected: $ledgerPayments,
            paymentVariance: $paymentDiff,
            analyticsCashRefunded: $analyticsCashRefunded,
            ledgerRefundsPaid: $ledgerRefunds,
            refundVariance: $refundDiff,
            analyticsNetCash: $analyticsNetCash,
            ledgerNetCash: $ledgerNetCash,
            netCashVariance: $netDiff,
            status: $status,
            discrepancies: $discrepancies
        );
    }

    /**
     * @return array{cash_collected: float, refunds_issued: float}|null
     */
    private function queryAnalyticsAggregations(string $startDate, string $endDate, string $currency): ?array
    {
        try {
            $rows = $this->db->select(
                'SELECT COALESCE(SUM(cash_collected), 0) as cash, COALESCE(SUM(refunds_issued), 0) as refunds
                 FROM analytics_daily_aggregations
                 WHERE date >= :s AND date <= :e AND currency = :c',
                ['s' => $startDate, 'e' => $endDate, 'c' => $currency]
            );

            if (!empty($rows)) {
                return [
                    'cash_collected' => (float) ($rows[0]['cash'] ?? 0.0),
                    'refunds_issued' => (float) ($rows[0]['refunds'] ?? 0.0),
                ];
            }
        } catch (\Throwable) {
            // Table may not exist yet or query failed
        }

        return null;
    }

    /**
     * @return array{cash_collected: float, refunds_issued: float}
     */
    private function queryTransactionalPayments(string $startDate, string $endDate, string $currency): array
    {
        $cash = 0.0;
        $refunds = 0.0;

        try {
            $payRows = $this->db->select(
                "SELECT COALESCE(SUM(amount), 0) as cash
                 FROM payments
                 WHERE date(created_at) >= :s AND date(created_at) <= :e
                   AND status = 'completed' AND currency = :c",
                ['s' => $startDate, 'e' => $endDate, 'c' => $currency]
            );
            $cash = (float) ($payRows[0]['cash'] ?? 0.0);
        } catch (\Throwable) {
        }

        try {
            $refRows = $this->db->select(
                "SELECT COALESCE(SUM(amount), 0) as refunds
                 FROM payment_refunds
                 WHERE date(created_at) >= :s AND date(created_at) <= :e
                   AND status = 'completed' AND currency = :c",
                ['s' => $startDate, 'e' => $endDate, 'c' => $currency]
            );
            $refunds = (float) ($refRows[0]['refunds'] ?? 0.0);
        } catch (\Throwable) {
        }

        return [
            'cash_collected' => $cash,
            'refunds_issued' => $refunds,
        ];
    }

    /**
     * @return array{payments: float, refunds: float}
     */
    private function queryLedgerTransactions(string $startDate, string $endDate, string $currency): array
    {
        $payments = 0.0;
        $refunds = 0.0;

        try {
            // Ledger credit entries where source is payment
            $creditRows = $this->db->select(
                "SELECT COALESCE(SUM(amount_minor), 0) as total_minor
                 FROM financial_account_transactions
                 WHERE substr(transaction_date, 1, 10) >= :s AND substr(transaction_date, 1, 10) <= :e
                   AND currency_code = :c
                   AND type = :type AND source = :src",
                [
                    's' => $startDate,
                    'e' => $endDate,
                    'c' => $currency,
                    'type' => AccountTransaction::TYPE_CREDIT,
                    'src' => AccountTransaction::SOURCE_PAYMENT,
                ]
            );
            $payments = ((int) ($creditRows[0]['total_minor'] ?? 0)) / 100.0;

            // Ledger debit entries where source is refund
            $debitRows = $this->db->select(
                "SELECT COALESCE(SUM(amount_minor), 0) as total_minor
                 FROM financial_account_transactions
                 WHERE substr(transaction_date, 1, 10) >= :s AND substr(transaction_date, 1, 10) <= :e
                   AND currency_code = :c
                   AND type = :type AND source = :src",
                [
                    's' => $startDate,
                    'e' => $endDate,
                    'c' => $currency,
                    'type' => AccountTransaction::TYPE_DEBIT,
                    'src' => AccountTransaction::SOURCE_REFUND,
                ]
            );
            $refunds = ((int) ($debitRows[0]['total_minor'] ?? 0)) / 100.0;
        } catch (\Throwable) {
        }

        return [
            'payments' => $payments,
            'refunds' => $refunds,
        ];
    }

    private function validateDates(string $startDate, string $endDate): void
    {
        $start = DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
        $end = DateTimeImmutable::createFromFormat('Y-m-d', $endDate);

        if ($start === false || $end === false) {
            throw new ValidationException(['dates' => 'Invalid date format, YYYY-MM-DD required.'], 'Invalid date format');
        }

        if ($start > $end) {
            throw new ValidationException(['dates' => 'Start date cannot be after end date.'], 'Start date cannot be after end date');
        }
    }
}
