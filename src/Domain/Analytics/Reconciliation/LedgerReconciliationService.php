<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Reconciliation;

use Coleza\Domain\Analytics\Financial\FinancialPeriodSummary;
use Coleza\Domain\Finance\Accounts\AccountTransaction;
use Coleza\Foundation\Database\Connection;
use DateTimeImmutable;
use Coleza\Domain\Analytics\AnalyticsInput;
use Coleza\Domain\Analytics\Financial\FinancialSourceReader;
use Coleza\Domain\Analytics\ReadModels\DailyAggregationEvidence;
use LogicException;

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
            $analyticsCashRefunded = $aggData['cash_refunded'];
        } else {
            // Fallback to payments/refunds tables
            $paymentData = $this->queryTransactionalPayments($startDate, $endDate, $curr);
            $analyticsCashCollected = $paymentData['cash_collected'];
            $analyticsCashRefunded = $paymentData['cash_refunded'];
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

        foreach (($aggData['issues'] ?? []) as $issue) { $discrepancies[] = $issue; }
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
        $this->validateDates($startDate, $endDate);
        [$curr] = AnalyticsInput::currency($this->db, $currency);
        if ($summary->getStartDate() !== $startDate || $summary->getEndDate() !== $endDate || $summary->getCurrency() !== $curr) {
            throw new LogicException('Financial summary period/currency differs from reconciliation scope.');
        }
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

    private function queryAnalyticsAggregations(string $startDate, string $endDate, string $currency): ?array
    {
        [, $places] = AnalyticsInput::currency($this->db, $currency);
        DailyAggregationEvidence::assertInstalled($this->db);
        $rows = $this->db->select('SELECT * FROM analytics_daily_aggregations WHERE date >= ? AND date <= ? AND currency = ? ORDER BY date', [$startDate, $endDate, $currency]);
        if ($rows === []) { return null; }
        $date = new DateTimeImmutable($startDate); $cash = 0.0; $refunds = 0.0; $issues = [];
        foreach ($rows as $row) {
            if ($row['date'] !== $date->format('Y-m-d')) { throw new LogicException('Complete daily coverage required for reconciliation.'); }
            try { DailyAggregationEvidence::verify($row, $places); }
            catch (LogicException) { $issues[] = 'Unverified daily aggregation: ' . $row['date']; }
            $cash += (float) $row['cash_collected']; $refunds += (float) $row['cash_refunded'];
            $date = $date->modify('+1 day');
        }
        if ($date->format('Y-m-d') !== (new DateTimeImmutable($endDate))->modify('+1 day')->format('Y-m-d')) { throw new LogicException('Complete daily coverage required for reconciliation.'); }
        return ['cash_collected' => round($cash, 2), 'cash_refunded' => round($refunds, 2), 'issues' => $issues];
    }

    private function queryTransactionalPayments(string $startDate, string $endDate, string $currency): array
    {
        return (new FinancialSourceReader($this->db))->period($startDate, $endDate, $currency);
    }

    private function queryLedgerTransactions(string $startDate, string $endDate, string $currency): array
    {
        [$start, $end] = AnalyticsInput::range($startDate, $endDate);
        [$code, , $divisor] = AnalyticsInput::currency($this->db, $currency);
        $values = [];
        foreach (['payments' => [AccountTransaction::TYPE_CREDIT, AccountTransaction::SOURCE_PAYMENT], 'refunds' => [AccountTransaction::TYPE_DEBIT, AccountTransaction::SOURCE_REFUND]] as $key => [$type, $source]) {
            $rows = $this->db->select('SELECT amount_minor FROM financial_account_transactions WHERE transaction_date >= ? AND transaction_date < ? AND currency_code = ? AND type = ? AND source = ?', [$startDate, substr($end, 0, 10), $code, $type, $source]);
            $values[$key] = round(AnalyticsInput::total($rows, 'amount_minor') / $divisor, 2);
        }
        return $values;
    }

    private function validateDates(string $startDate, string $endDate): void
    {
        AnalyticsInput::range($startDate, $endDate);
    }
}
