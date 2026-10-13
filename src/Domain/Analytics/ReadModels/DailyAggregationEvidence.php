<?php
declare(strict_types=1);
namespace Coleza\Domain\Analytics\ReadModels;

use Coleza\Domain\Analytics\AnalyticsSchema;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Database\PdoSchema;
use LogicException;

/** Derived-row consistency marker; not a signature or proof of immutable historical source data. */
final class DailyAggregationEvidence
{
    private const POLICY = 'canonical_financial_events:v1';
    private const MONEY = ['gross_invoiced', 'net_invoiced', 'tax_billed', 'cash_collected', 'cash_refunded', 'net_cash_flow', 'gateway_fees'];
    private const COUNTS = ['invoices_count', 'new_orders_count', 'new_customers_count', 'tickets_opened_count', 'tickets_resolved_count'];

    public static function install(Connection $db): void
    {
        AnalyticsSchema::assertCaller($db);
        if (!PdoSchema::useInstalledSchema($db, ReadModelAggregationService::schemaRequirements(), installSchema: true)) {
            throw new LogicException('Install analytics base schema before daily evidence upgrade.');
        }
        AnalyticsSchema::ensureIndexes($db, ReadModelAggregationService::indexRequirements(), false);
        if (self::column($db) !== null) { self::assertInstalled($db); return; }
        if ($db->getDriverName() === 'mysql' && $db->getPdo()->inTransaction()) {
            throw new LogicException('Install daily evidence schema before starting an application transaction.');
        }
        // Nullable with no trusted default: every historical row remains explicitly unverified.
        $db->statement('ALTER TABLE analytics_daily_aggregations ADD COLUMN calculation_evidence TEXT NULL');
        self::assertInstalled($db);
    }

    public static function assertInstalled(Connection $db): void
    {
        AnalyticsSchema::assertCaller($db);
        $column = self::column($db);
        $valid = $column !== null && ($db->getDriverName() === 'mysql'
            ? strtolower($column['Type']) === 'text' && $column['Null'] === 'YES' && ($column['Default'] === null || $column['Default'] === 'NULL')
            : strtoupper($column['type']) === 'TEXT' && (int) $column['notnull'] === 0 && $column['dflt_value'] === null);
        if (!$valid) { throw new LogicException('Installed daily evidence schema is missing or incompatible; explicit upgrade required.'); }
    }

    private static function column(Connection $db): ?array
    {
        if ($db->getDriverName() === 'mysql') {
            return $db->selectOne("SELECT COLUMN_TYPE AS Type, IS_NULLABLE AS `Null`, COLUMN_DEFAULT AS `Default` FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'analytics_daily_aggregations' AND column_name = 'calculation_evidence'");
        }
        foreach ($db->select('PRAGMA table_info(analytics_daily_aggregations)') as $column) {
            if ($column['name'] === 'calculation_evidence') { return $column; }
        }
        return null;
    }

    public static function create(array $row, int $places): string
    {
        return json_encode(['policy' => self::POLICY, 'currency_decimal_places' => $places, 'digest' => self::digest($row, $places)], JSON_THROW_ON_ERROR);
    }

    public static function verify(array $row, int $places): void
    {
        $raw = $row['calculation_evidence'] ?? null;
        $evidence = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($evidence) || ($evidence['policy'] ?? null) !== self::POLICY
            || ($evidence['currency_decimal_places'] ?? null) !== $places
            || !is_string($evidence['digest'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $evidence['digest'])
            || !hash_equals(self::digest($row, $places), $evidence['digest'])) {
            throw new LogicException('Stored daily evidence requires explicit reconciliation or canonical rebuild.');
        }
    }

    private static function digest(array $row, int $places): string
    {
        $data = ['policy' => self::POLICY, 'currency_decimal_places' => $places];
        foreach (['date', 'currency', 'rebuilt_at'] as $field) {
            if (!is_string($row[$field] ?? null)) { throw new LogicException('Invalid daily evidence content; explicit reconciliation required.'); }
            $data[$field] = $row[$field];
        }
        // PDO returns SQLite numbers and MariaDB DECIMAL strings; bind their identical stored value.
        foreach (self::MONEY as $field) {
            $value = $row[$field] ?? null;
            if ((!is_string($value) && !is_int($value) && !is_float($value))
                || !preg_match('/^(-?)(\d{1,10})(?:\.(\d{1,2}))?$/D', (string) $value, $parts)) {
                throw new LogicException('Invalid daily evidence money; explicit reconciliation required.');
            }
            $cents = (int) $parts[2] * 100 + (int) str_pad($parts[3] ?? '', 2, '0');
            $data[$field] = $parts[1] === '-' ? -$cents : $cents;
        }
        foreach (self::COUNTS as $field) {
            $value = filter_var($row[$field] ?? null, FILTER_VALIDATE_INT);
            if ($value === false || $value < 0) { throw new LogicException('Invalid daily evidence count; explicit reconciliation required.'); }
            $data[$field] = $value;
        }
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }
}
