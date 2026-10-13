<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
use Coleza\Foundation\Database\Connection;
final class DailySourceFixture
{
    public static function install(Connection $db): void
    {
        (new \Coleza\Foundation\Database\Migrator($db))->migrate(dirname(__DIR__, 2) . '/database/migrations');
        foreach (['USD' => 2, 'EUR' => 2, 'JPY' => 0, 'BHD' => 3] as $code => $places) {
            if ($db->selectOne('SELECT code FROM currencies WHERE code = ?', [$code]) === null) {
                (new \Coleza\Domain\Finance\Currency\CurrencyService($db))->createCurrency(['code' => $code, 'name' => $code, 'symbol' => $code, 'decimal_places' => $places]);
            }
        }
    }
    public static function invoice(Connection $db, int $total, int $subtotal, int $tax, string $status = 'unpaid', string $code = 'USD', string $date = '2026-10-13'): void
    {
        $db->insert('invoices', ['invoice_number' => 'DINV-' . bin2hex(random_bytes(6)), 'user_id' => 1, 'status' => $status, 'currency_code' => $code, 'total_minor' => $total, 'subtotal_minor' => $subtotal, 'tax_total_minor' => $tax, 'issue_date' => $date, 'due_date' => '2026-11-01', 'created_at' => '2026-10-01 12:00:00']);
    }
    public static function payment(Connection $db, int $amount, int $fee = 0, string $status = 'completed', string $code = 'USD', ?string $paidAt = '2026-10-13 12:00:00'): void
    {
        $db->insert('payments', ['payment_number' => 'DPAY-' . bin2hex(random_bytes(6)), 'user_id' => 1, 'payment_method' => 'manual', 'amount_minor' => $amount, 'fee_minor' => $fee, 'net_amount_minor' => $amount - $fee, 'currency_code' => $code, 'status' => $status, 'paid_at' => $paidAt, 'created_at' => '2026-10-01 12:00:00']);
    }
    public static function refund(Connection $db, int $amount, string $code = 'USD', string $refundedAt = '2026-10-13 12:00:00'): void
    {
        $db->insert('refunds', ['refund_number' => 'DREF-' . bin2hex(random_bytes(6)), 'payment_id' => 1, 'user_id' => 1, 'amount_minor' => $amount, 'currency_code' => $code, 'reason' => 'Fixture', 'refunded_at' => $refundedAt]);
    }
}
