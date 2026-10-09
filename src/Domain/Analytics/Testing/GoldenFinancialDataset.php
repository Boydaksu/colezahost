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

        // 2. Seed Invoices
        // Inv 1: $500 ($450 net + $50 tax) - PAID
        $db->statement(
            "INSERT INTO invoices (id, invoice_number, total_amount, subtotal_amount, tax_amount, status, currency, created_at)
             VALUES (1, 'INV-2026-001', 500.00, 450.00, 50.00, 'paid', 'USD', '2026-10-05 10:00:00')"
        );

        // Inv 2: $450 ($400 net + $50 tax) - PAID
        $db->statement(
            "INSERT INTO invoices (id, invoice_number, total_amount, subtotal_amount, tax_amount, status, currency, created_at)
             VALUES (2, 'INV-2026-002', 450.00, 400.00, 50.00, 'paid', 'USD', '2026-10-12 11:30:00')"
        );

        // Inv 3: $300 ($250 net + $50 tax) - UNPAID (accounts receivable)
        $db->statement(
            "INSERT INTO invoices (id, invoice_number, total_amount, subtotal_amount, tax_amount, status, currency, created_at)
             VALUES (3, 'INV-2026-003', 300.00, 250.00, 50.00, 'unpaid', 'USD', '2026-10-20 09:15:00')"
        );

        // Inv 4: $200 - CANCELLED (should NOT count towards gross invoiced)
        $db->statement(
            "INSERT INTO invoices (id, invoice_number, total_amount, subtotal_amount, tax_amount, status, currency, created_at)
             VALUES (4, 'INV-2026-004', 200.00, 180.00, 20.00, 'cancelled', 'USD', '2026-10-22 14:00:00')"
        );

        // 3. Seed Payments
        // Payment 1: $500 for INV-001
        $db->statement(
            "INSERT INTO payments (id, invoice_id, amount, currency, status, gateway, created_at)
             VALUES (1, 1, 500.00, 'USD', 'completed', 'stripe', '2026-10-05 10:05:00')"
        );

        // Payment 2: $450 for INV-002
        $db->statement(
            "INSERT INTO payments (id, invoice_id, amount, currency, status, gateway, created_at)
             VALUES (2, 2, 450.00, 'USD', 'completed', 'stripe', '2026-10-12 11:35:00')"
        );

        $db->statement(
            "INSERT INTO payment_refunds (id, payment_id, amount, currency, status, reason, created_at)
             VALUES (1, 1, 100.00, 'USD', 'completed', 'Customer service goodwill', '2026-10-18 16:00:00')"
        );
        $db->statement(
            "INSERT INTO refunds (id, payment_id, amount, currency, status, reason, created_at)
             VALUES (1, 1, 100.00, 'USD', 'completed', 'Customer service goodwill', '2026-10-18 16:00:00')"
        );

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

        // 6. Seed Analytics Daily Aggregations (Precomputed read models)
        $db->statement(
            "INSERT INTO analytics_daily_aggregations
             (date, currency, new_mrr, expansion_mrr, churned_mrr, invoiced_amount, cash_collected, refunds_issued, net_cash_flow, active_subscriptions, rebuilt_at)
             VALUES
             ('2026-10-05', 'USD', 50.00, 0.00, 0.00, 500.00, 500.00, 0.00, 500.00, 10, '2026-10-31 23:59:59'),
             ('2026-10-12', 'USD', 40.00, 0.00, 0.00, 450.00, 450.00, 0.00, 450.00, 12, '2026-10-31 23:59:59'),
             ('2026-10-18', 'USD', 0.00, 0.00, 0.00, 0.00, 0.00, 100.00, -100.00, 12, '2026-10-31 23:59:59'),
             ('2026-10-20', 'USD', 0.00, 0.00, 0.00, 300.00, 0.00, 0.00, 0.00, 12, '2026-10-31 23:59:59')"
        );
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
        $db->statement(
            'CREATE TABLE IF NOT EXISTS financial_accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                code VARCHAR(50) NOT NULL UNIQUE,
                name VARCHAR(100) NOT NULL,
                account_type VARCHAR(30) NOT NULL,
                currency_code VARCHAR(3) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS financial_account_transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                entry_number VARCHAR(50) NOT NULL UNIQUE,
                account_id INT NOT NULL,
                type VARCHAR(20) NOT NULL,
                amount_minor INT NOT NULL,
                balance_after_minor INT NOT NULL,
                currency_code VARCHAR(3) NOT NULL,
                source VARCHAR(50) NOT NULL,
                description VARCHAR(255) NOT NULL,
                reference_id INT NULL,
                transaction_date VARCHAR(30) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS invoices (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                invoice_number VARCHAR(50) NOT NULL,
                total_amount DECIMAL(10,2) NOT NULL,
                subtotal_amount DECIMAL(10,2) NOT NULL,
                tax_amount DECIMAL(10,2) NOT NULL,
                status VARCHAR(30) NOT NULL,
                currency VARCHAR(3) NOT NULL DEFAULT "USD",
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS payments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                invoice_id INT NULL,
                amount DECIMAL(10,2) NOT NULL,
                currency VARCHAR(3) NOT NULL DEFAULT "USD",
                status VARCHAR(30) NOT NULL,
                gateway VARCHAR(50) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS payment_refunds (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                payment_id INT NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                currency VARCHAR(3) NOT NULL DEFAULT "USD",
                status VARCHAR(30) NOT NULL,
                reason VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS refunds (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                payment_id INT NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                currency VARCHAR(3) NOT NULL DEFAULT "USD",
                status VARCHAR(30) NOT NULL,
                reason VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS analytics_daily_aggregations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                date VARCHAR(10) NOT NULL,
                currency VARCHAR(3) NOT NULL,
                new_mrr DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                expansion_mrr DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                churned_mrr DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                invoiced_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                cash_collected DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                refunds_issued DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                net_cash_flow DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                active_subscriptions INT NOT NULL DEFAULT 0,
                rebuilt_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )'
        );
    }
}
