<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
use Coleza\Foundation\Database\Connection;
final class SubscriptionSourceFixture
{
    public static function install(Connection $db): void
    {
        (new \Coleza\Domain\Commerce\Services\ServiceService($db))->ensureTables();
        $currencies = new \Coleza\Domain\Finance\Currency\CurrencyService($db); $currencies->ensureTables();
        foreach (['USD', 'EUR'] as $code) {
            if ($db->selectOne('SELECT code FROM currencies WHERE code = ?', [$code]) === null) {
                $currencies->createCurrency(['code' => $code, 'name' => $code, 'symbol' => $code, 'decimal_places' => 2]);
            }
        }
    }
    public static function service(Connection $db, int $minor, int $user = 1, string $cycle = 'monthly'): void
    {
        $db->insert('services', ['service_number' => 'FIX-' . bin2hex(random_bytes(6)), 'user_id' => $user, 'product_id' => 1, 'status' => 'active', 'billing_cycle' => $cycle, 'recurring_amount_minor' => $minor, 'currency_code' => 'USD', 'registration_date' => '2026-01-01', 'next_due_date' => '2026-11-01']);
    }
}
