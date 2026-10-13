<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Testing;

use Coleza\Domain\Finance\Accounts\AccountTransaction;
use Coleza\Foundation\Database\Connection;

/**
 * Golden Financial Dataset generator providing deterministic, audited test fixtures
 * to verify source-ledger reconciliation and zero unexplained diff between analytics and accounting.
 */
final class GoldenFinancialDataset
{
    public const DEFAULT_CURRENCY = 'USD';
    public const DEFAULT_START_DATE = '2026-10-01';
    public const DEFAULT_END_DATE = '2026-10-31';

    // Canonical totals for this golden fixture
    public const EXPECTED_GROSS_INVOICED = 1250.00;
    public const EXPECTED_NET_INVOICED = 1100.00;
    public const EXPECTED_TAX_BILLED = 150.00;
    public const EXPECTED_CASH_COLLECTED = 950.00;
    public const EXPECTED_REFUNDS_ISSUED = 100.00;
    public const EXPECTED_NET_CASH_FLOW = 850.00;

    // In ledger minor units (cents)
    public const EXPECTED_LEDGER_CREDIT_MINOR = 95000;
    public const EXPECTED_LEDGER_DEBIT_MINOR = 10000;
    public const EXPECTED_LEDGER_NET_MINOR = 85000;

    /**
     * Seeds the full canonical financial fixture into the database.
     */
    public static function seed(Connection $db): void
    {
        self::ensureSchema($db);

        // 1. Seed Financial Account
        $db->statement(
            "INSERT INTO financial_accounts (id, code, name, account_type, currency_code, is_active, is_default)
             VALUES (1, 'STRIPE-MAIN', 'Stripe Operating Account', 'gateway', 'USD', 1, 1)"
        );

        foreach ([[1, 50000, 45000, 5000, 'paid', '2026-10-05'], [2, 45000, 40000, 5000, 'paid', '2026-10-12'], [3, 30000, 25000, 5000, 'unpaid', '2026-10-20'], [4, 20000, 18000, 2000, 'cancelled', '2026-10-22']] as [$id, $total, $net, $tax, $status, $date]) {
            $db->insert('invoices', ['id' => $id, 'invoice_number' => 'INV-2026-' . $id, 'user_id' => 1, 'total_minor' => $total, 'subtotal_minor' => $net, 'tax_total_minor' => $tax, 'paid_amount_minor' => $status === 'paid' ? $total : 0, 'status' => $status, 'currency_code' => 'USD', 'issue_date' => $date, 'due_date' => '2026-11-01']);
        }
        foreach ([[1, 50000, 'partially_refunded', '2026-10-05'], [2, 45000, 'completed', '2026-10-12']] as [$id, $amount, $status, $date]) {
            $db->insert('payments', ['id' => $id, 'payment_number' => 'PAY-2026-' . $id, 'invoice_id' => $id, 'user_id' => 1, 'amount_minor' => $amount, 'fee_minor' => 0, 'net_amount_minor' => $amount, 'currency_code' => 'USD', 'status' => $status, 'payment_method' => 'manual', 'paid_at' => $date . ' 10:00:00']);
        }
        $db->insert('refunds', ['id' => 1, 'refund_number' => 'REF-2026-1', 'payment_id' => 1, 'user_id' => 1, 'amount_minor' => 10000, 'currency_code' => 'USD', 'reason' => 'Customer service goodwill', 'refunded_at' => '2026-10-18 16:00:00']);

        // 5. Seed Authoritative Ledger Entries (financial_account_transactions)
        // Entry 1: Payment 1 deposit +$500.00 (50,000 cents)
        $db->statement(
            "INSERT INTO financial_account_transactions
             (id, entry_number, account_id, type, amount_minor, balance_after_minor, currency_code, source, description, reference_id, transaction_date)
             VALUES (1, 'TXN-2026-001', 1, :type, 50000, 50000, 'USD', :src, 'Payment for INV-2026-001', 1, '2026-10-05')",
            ['type' => AccountTransaction::TYPE_CREDIT, 'src' => AccountTransaction::SOURCE_PAYMENT]
        );

        // Entry 2: Payment 2 deposit +$450.00 (45,000 cents)
        $db->statement(
            "INSERT INTO financial_account_transactions
             (id, entry_number, account_id, type, amount_minor, balance_after_minor, currency_code, source, description, reference_id, transaction_date)
             VALUES (2, 'TXN-2026-002', 1, :type, 45000, 95000, 'USD', :src, 'Payment for INV-2026-002', 2, '2026-10-12')",
            ['type' => AccountTransaction::TYPE_CREDIT, 'src' => AccountTransaction::SOURCE_PAYMENT]
        );

        // Entry 3: Refund debit -$100.00 (10,000 cents)
        $db->statement(
            "INSERT INTO financial_account_transactions
             (id, entry_number, account_id, type, amount_minor, balance_after_minor, currency_code, source, description, reference_id, transaction_date)
             VALUES (3, 'TXN-2026-003', 1, :type, 10000, 85000, 'USD', :src, 'Refund on Payment #1', 1, '2026-10-18')",
            ['type' => AccountTransaction::TYPE_DEBIT, 'src' => AccountTransaction::SOURCE_REFUND]
        );

        (new \Coleza\Domain\Analytics\ReadModels\ReadModelAggregationService($db))->rebuildDateRange(self::DEFAULT_START_DATE, self::DEFAULT_END_DATE);
    }

    /**
     * Injects a synthetic anomaly / unrecorded transaction into the ledger to simulate reconciliation discrepancies.
     */
    public static function injectLedgerDiscrepancy(Connection $db, int $amountMinor = 2500): void
    {
        $db->statement(
            "INSERT INTO financial_account_transactions
             (id, entry_number, account_id, type, amount_minor, balance_after_minor, currency_code, source, description, reference_id, transaction_date)
             VALUES (99, 'TXN-ANOMALY', 1, :type, :amt, 87500, 'USD', :src, 'Ghost credit not in billing', 999, '2026-10-25')",
            ['type' => AccountTransaction::TYPE_CREDIT, 'amt' => $amountMinor, 'src' => AccountTransaction::SOURCE_PAYMENT]
        );
    }

    /**
     * Injects an analytics discrepancy (e.g. dropped aggregation or corrupted rollup).
     */
    public static function injectAnalyticsDiscrepancy(Connection $db, float $amount = 50.0): void
    {
        $db->statement(
            "UPDATE analytics_daily_aggregations
             SET cash_collected = cash_collected + :amt
             WHERE date = '2026-10-05'",
            ['amt' => $amount]
        );
    }

    public static function ensureSchema(Connection $db): void
    {
        (new \Coleza\Foundation\Database\Migrator($db))->migrate(dirname(__DIR__, 4) . '/database/migrations');
        if ($db->selectOne('SELECT code FROM currencies WHERE code = ?', ['USD']) === null) {
            (new \Coleza\Domain\Finance\Currency\CurrencyService($db))->createCurrency(['code' => 'USD', 'name' => 'USD', 'symbol' => '$', 'decimal_places' => 2]);
        }
    }
}
