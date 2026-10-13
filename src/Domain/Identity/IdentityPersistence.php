<?php
declare(strict_types=1);
namespace Coleza\Domain\Identity;
use Coleza\Foundation\Database\{Connection, PdoSchema};

final class IdentityPersistence
{
    public static function installed(Connection $db, array $requirements, bool $installation = true, array $unique = [], array $primary = [], array $onlyPrimary = []): bool
    {
        self::assertCaller($db);
        $installed = PdoSchema::useInstalledSchema($db, $requirements, installSchema: $installation);
        foreach ($requirements as $table => $columns) {
            if ($db->getDriverName() === 'mysql') {
                $row = $db->selectOne('SELECT ENGINE FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]);
                if ($row === null) { continue; }
                if (strcasecmp((string) $row['ENGINE'], 'InnoDB') !== 0) { throw new \LogicException('Identity tables require InnoDB; explicit upgrade required.'); }
                $indexes = $db->select('SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SUB_PART FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX', [$table]);
                $pk = array_values(array_filter($indexes, static fn(array $i): bool => $i['INDEX_NAME'] === 'PRIMARY'));
                if (array_column($pk, 'COLUMN_NAME') !== [$primary[$table] ?? 'id'] || $pk[0]['SUB_PART'] !== null) { throw new \LogicException('Identity table requires a full canonical primary key.'); }
                if (in_array($table, $onlyPrimary, true)) {
                    foreach ($indexes as $index) { if ((int) $index['NON_UNIQUE'] === 0 && $index['INDEX_NAME'] !== 'PRIMARY') { throw new \LogicException('Unexpected unique identity can redirect lifecycle upsert; explicit upgrade required.'); } }
                }
                if (isset($unique[$table])) {
                    $found = false; $groups = [];
                    foreach ($indexes as $index) { if ((int) $index['NON_UNIQUE'] === 0) { $groups[$index['INDEX_NAME']][] = $index; } }
                    foreach ($groups as $group) { if (array_column($group, 'COLUMN_NAME') === [$unique[$table]] && $group[0]['SUB_PART'] === null) { $found = true; } }
                    if (!$found) { throw new \LogicException('Identity table requires its canonical unique identity.'); }
                }
            } else {
                $rows = $db->select('PRAGMA table_info(' . $table . ')'); if ($rows === []) { continue; }
                $pk = array_values(array_filter($rows, static fn(array $r): bool => (int) $r['pk'] > 0));
                if (array_column($pk, 'name') !== [$primary[$table] ?? 'id']) { throw new \LogicException('Identity table requires its canonical primary key.'); }
                if (in_array($table, $onlyPrimary, true)) {
                    foreach ($db->select('PRAGMA index_list(' . $table . ')') as $index) { if ((int) $index['unique'] === 1 && $index['origin'] !== 'pk') { throw new \LogicException('Unexpected unique identity can redirect lifecycle upsert; explicit upgrade required.'); } }
                }
                if (isset($unique[$table])) {
                    $found = false;
                    foreach ($db->select('PRAGMA index_list(' . $table . ')') as $index) {
                        if ((int) $index['unique'] !== 1 || (int) $index['partial'] !== 0) { continue; }
                        $name = str_replace("'", "''", $index['name']);
                        if (array_column($db->select("PRAGMA index_info('{$name}')"), 'name') === [$unique[$table]]) { $found = true; }
                    }
                    if (!$found) { throw new \LogicException('Identity table requires its canonical unique identity.'); }
                }
            }
        }
        if (!$installed) { PdoSchema::autoIncrement($db->getPdo()); }
        return $installed;
    }
    public static function assertCaller(Connection $db): void
    {
        if ($db->inTransaction() && !$db->getPdo()->inTransaction()) { throw new \LogicException('Identity caller transaction ended unexpectedly.'); }
    }
    public static function run(Connection $db, callable $callback): mixed
    {
        self::assertCaller($db);
        if (!$db->getPdo()->inTransaction()) { return $db->transaction($callback); }
        $name = 'identity_' . bin2hex(random_bytes(8)); $db->statement('SAVEPOINT ' . $name);
        try { $result = $callback($db); $db->statement('RELEASE SAVEPOINT ' . $name); return $result; }
        catch (\Throwable $error) {
            if ($db->getPdo()->inTransaction()) { $db->statement('ROLLBACK TO SAVEPOINT ' . $name); $db->statement('RELEASE SAVEPOINT ' . $name); }
            throw $error;
        }
    }
    public static function lock(Connection $db): string { return $db->getDriverName() === 'mysql' ? ' FOR UPDATE' : ''; }
    public static function expiresAt(int $ttl): int
    {
        $now = time(); if ($ttl < 1 || $ttl > 2147483647 - $now) { throw new \InvalidArgumentException('Invalid identity token lifetime.'); }
        return $now + $ttl;
    }
}
