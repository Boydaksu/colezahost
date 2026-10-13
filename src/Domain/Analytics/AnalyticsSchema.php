<?php
declare(strict_types=1);
namespace Coleza\Domain\Analytics;
use Coleza\Foundation\Database\Connection;

/** Constraints shared by the derived analytics tables; never changes historical rows. */
final class AnalyticsSchema
{
    public static function assertCaller(Connection $db): void
    {
        if ($db->inTransaction() && !$db->getPdo()->inTransaction()) {
            throw new \LogicException('Analytics caller transaction ended unexpectedly.');
        }
    }

    /** @param array<string, array{string, string}> $definitions Table => [period column, index name]. */
    public static function preflightExisting(Connection $db, array $definitions): void
    {
        foreach ($definitions as $table => [$period, $name]) {
            $exists = $db->getDriverName() === 'mysql'
                ? $db->selectOne('SELECT ENGINE FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table])
                : $db->selectOne("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]);
            if ($exists === null) { continue; }
            if ($db->getDriverName() === 'mysql') {
                if (strcasecmp((string) $exists['ENGINE'], 'InnoDB') !== 0) { throw new \LogicException('Analytics tables require InnoDB; explicit upgrade required.'); }
                $pk = array_values(array_filter($db->select("SHOW INDEX FROM {$table}"), fn($row) => $row['Key_name'] === 'PRIMARY'));
                $validPk = count($pk) === 1 && $pk[0]['Column_name'] === 'id';
                $columns = array_column($db->select("SHOW COLUMNS FROM {$table}"), null, 'Field');
                $required = ($columns[$period]['Null'] ?? '') === 'NO' && ($columns['currency']['Null'] ?? '') === 'NO';
            } else {
                $columns = array_column($db->select("PRAGMA table_info({$table})"), null, 'name');
                $pk = array_values(array_filter($columns, fn($row) => (int) $row['pk'] > 0));
                $validPk = count($pk) === 1 && $pk[0]['name'] === 'id';
                $required = (int) ($columns[$period]['notnull'] ?? 0) === 1 && (int) ($columns['currency']['notnull'] ?? 0) === 1;
            }
            if (!$validPk || !$required) { throw new \LogicException('Analytics requires id primary key and non-null period/currency; explicit upgrade required.'); }
            $hasUnique = self::hasUnique($db, $table, $period);
            if (!$hasUnique && $db->selectOne("SELECT `{$period}`, currency FROM {$table} GROUP BY `{$period}`, currency HAVING COUNT(*) > 1 LIMIT 1") !== null) {
                throw new \LogicException('Duplicate analytics period/currency requires explicit reconciliation before upgrade.');
            }
            if (!$hasUnique && self::indexNameExists($db, $table, $name)) {
                throw new \LogicException('Analytics index name conflicts with required unique key; explicit upgrade required.');
            }
        }
    }

    public static function ensureIndexes(Connection $db, array $definitions, bool $installSchema): void
    {
        self::preflightExisting($db, $definitions);
        foreach ($definitions as $table => [$period, $name]) {
            if (self::hasUnique($db, $table, $period)) { continue; }
            if (!$installSchema) { throw new \LogicException('Analytics unique period/currency schema is missing; explicit upgrade required.'); }
            if ($db->getDriverName() === 'mysql' && $db->getPdo()->inTransaction()) {
                throw new \LogicException('Install analytics indexes before starting an application transaction.');
            }
            $db->statement("CREATE UNIQUE INDEX {$name} ON {$table} (`{$period}`, currency)");
        }
    }

    private static function hasUnique(Connection $db, string $table, string $period): bool
    {
        if ($db->getDriverName() === 'mysql') {
            $indexes = [];
            foreach ($db->select("SHOW INDEX FROM {$table}") as $row) { $indexes[$row['Key_name']][] = $row; }
            foreach ($indexes as $rows) {
                usort($rows, fn($a, $b) => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index']);
                if (count($rows) === 2 && (int) $rows[0]['Non_unique'] === 0 && $rows[0]['Sub_part'] === null && $rows[1]['Sub_part'] === null && array_column($rows, 'Column_name') === [$period, 'currency']) { return true; }
            }
        } else {
            foreach ($db->select("PRAGMA index_list({$table})") as $index) {
                if ((int) $index['unique'] !== 1 || (int) $index['partial'] !== 0) { continue; }
                $name = str_replace("'", "''", $index['name']);
                if (array_column($db->select("PRAGMA index_info('{$name}')"), 'name') === [$period, 'currency']) { return true; }
            }
        }
        return false;
    }

    private static function indexNameExists(Connection $db, string $table, string $name): bool
    {
        return $db->getDriverName() === 'mysql'
            ? $db->selectOne('SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', [$table, $name]) !== null
            : $db->selectOne("SELECT 1 FROM sqlite_master WHERE type = 'index' AND name = ?", [$name]) !== null;
    }
}
