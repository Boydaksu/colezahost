<?php
declare(strict_types=1);
namespace Coleza\Foundation\Database;

/** Compatibility setup for API/session tables; installed schemas never execute DDL. */
final class ApiSessionSchema
{
    private const TABLES = [
        'api_keys' => ['id', 'user_id', 'name', 'key_prefix', 'key_hash', 'scopes', 'is_active', 'last_used_at', 'expires_at', 'created_at'],
        'api_request_logs' => ['id', 'key_id', 'method', 'path', 'status_code', 'duration_ms', 'ip_address', 'request_id', 'created_at'],
        'api_rate_limits' => ['rate_key', 'hits', 'reset_at'],
        'sessions' => ['id', 'user_id', 'ip_address', 'user_agent', 'payload', 'last_activity'],
    ];

    public static function installed(Connection $db, string $table, bool $installation = true): bool
    {
        if ($db->inTransaction() && !$db->getPdo()->inTransaction()) { throw new \LogicException('API/session caller transaction ended unexpectedly.'); }
        $columns = self::TABLES[$table] ?? throw new \InvalidArgumentException('Unknown API/session table.');
        $installed = PdoSchema::useInstalledSchema($db, [$table => $columns], installSchema: $installation);
        if (!$installed) { PdoSchema::autoIncrement($db->getPdo()); return false; }
        $primary = $table === 'api_rate_limits' ? 'rate_key' : 'id';
        if ($db->getDriverName() === 'mysql') {
            $row = $db->selectOne('SELECT ENGINE FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]);
            if (strcasecmp((string) $row['ENGINE'], 'InnoDB') !== 0) { throw new \LogicException('API/session tables require InnoDB; explicit upgrade required.'); }
            $indexes = $db->select('SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX, NON_UNIQUE, SUB_PART FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX', [$table]);
            $pk = array_values(array_filter($indexes, static fn(array $index): bool => $index['INDEX_NAME'] === 'PRIMARY'));
            if (array_column($pk, 'COLUMN_NAME') !== [$primary] || $pk[0]['SUB_PART'] !== null) { throw new \LogicException('API/session table requires its full canonical primary key.'); }
            if (in_array($table, ['sessions', 'api_rate_limits'], true)) {
                foreach ($indexes as $index) {
                    if ((int) $index['NON_UNIQUE'] === 0 && $index['INDEX_NAME'] !== 'PRIMARY') { throw new \LogicException('Unexpected unique key conflicts with API/session upsert identity; explicit upgrade required.'); }
                }
            }
            if ($table === 'api_keys') {
                $unique = [];
                foreach ($indexes as $index) {
                    if ((int) $index['NON_UNIQUE'] === 0) { $unique[$index['INDEX_NAME']][] = $index; }
                }
                $hashUnique = false;
                foreach ($unique as $index) {
                    if (count($index) === 1 && $index[0]['COLUMN_NAME'] === 'key_hash' && $index[0]['SUB_PART'] === null) { $hashUnique = true; }
                }
                if (!$hashUnique) { throw new \LogicException('API key hashes require a full unique key; explicit upgrade required.'); }
            }
        } else {
            $pk = array_values(array_filter($db->select('PRAGMA table_info(' . $table . ')'), static fn(array $column): bool => (int) $column['pk'] > 0));
            if (array_column($pk, 'name') !== [$primary]) { throw new \LogicException('API/session table requires its canonical primary key.'); }
            if (in_array($table, ['sessions', 'api_rate_limits'], true)) {
                foreach ($db->select('PRAGMA index_list(' . $table . ')') as $index) {
                    if ((int) $index['unique'] === 1 && $index['origin'] !== 'pk') { throw new \LogicException('Unexpected unique key conflicts with API/session upsert identity; explicit upgrade required.'); }
                }
            }
            if ($table === 'api_keys') {
                $hashUnique = false;
                foreach ($db->select('PRAGMA index_list(api_keys)') as $index) {
                    if ((int) $index['unique'] !== 1 || (int) $index['partial'] !== 0) { continue; }
                    $name = str_replace("'", "''", $index['name']);
                    if (array_column($db->select("PRAGMA index_info('{$name}')"), 'name') === ['key_hash']) { $hashUnique = true; }
                }
                if (!$hashUnique) { throw new \LogicException('API key hashes require a full unique key; explicit upgrade required.'); }
            }
        }
        return true;
    }
}
