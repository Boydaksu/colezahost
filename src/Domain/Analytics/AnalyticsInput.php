<?php
declare(strict_types=1);
namespace Coleza\Domain\Analytics;

use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use DateTimeImmutable;
use LogicException;

final class AnalyticsInput
{
    public static function date(string $value, string $field = 'date'): DateTimeImmutable
    {
        $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            $label = ucfirst(str_replace('_', ' ', $field));
            throw new ValidationException([$field => ["{$label} must follow YYYY-MM-DD format."]], "{$label} must follow YYYY-MM-DD format.");
        }
        return $parsed;
    }
    public static function range(string $start, string $end): array
    {
        self::date($start, 'start_date'); $last = self::date($end, 'end_date');
        if ($start > $end) { throw new ValidationException(['dates' => ['Start date cannot be after end date.']], 'Start date cannot be after end date.'); }
        return [$start . ' 00:00:00', $last->modify('+1 day')->format('Y-m-d') . ' 00:00:00'];
    }
    public static function currency(Connection $db, string $value): array
    {
        $code = strtoupper(trim($value));
        if (!preg_match('/^[A-Z]{3}$/D', $code)) { throw new ValidationException(['currency' => ['Invalid currency code.']], 'Invalid currency code.'); }
        $row = $db->selectOne('SELECT code, decimal_places FROM currencies WHERE code = ?', [$code]);
        if ($row === null) { throw new ValidationException(['currency' => ['Currency must exist in the installed registry.']], 'Unknown analytics currency.'); }
        $places = filter_var($row['decimal_places'], FILTER_VALIDATE_INT);
        if ($places === false || $places < 0 || $places > 2) { throw new LogicException('Analytics currency precision requires an explicit storage/metric upgrade.'); }
        return [$code, $places, 10 ** $places];
    }
    public static function minor(mixed $value): int
    {
        $minor = filter_var($value, FILTER_VALIDATE_INT);
        if ($minor === false || $minor < 0) { throw new LogicException('Invalid canonical analytics amount requires explicit reconciliation.'); }
        return $minor;
    }
    public static function total(array $rows, string $field): int
    {
        $total = 0;
        foreach ($rows as $row) {
            $amount = self::minor($row[$field] ?? null);
            if ($amount > PHP_INT_MAX - $total) { throw new LogicException('Overflowing canonical analytics amount requires explicit reconciliation.'); }
            $total += $amount;
        }
        return $total;
    }
}
