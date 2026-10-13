<?php

declare(strict_types=1);

namespace Coleza\Foundation\Database;

use PDO;

final class PdoSchema
{
    /** Explicit installation may use SQLite transactional DDL; application first-use may not. */
    public static function useInstalledSchema(Connection $db, array $tables, bool $installSchema = false): bool
    {
        if (!in_array($db->getDriverName(), ['sqlite', 'mysql'], true)) { throw new \RuntimeException('Unsupported schema database driver.'); }
        $missing = false;
        foreach ($tables as $table => $columns) {
            foreach (array_merge([$table], $columns) as $identifier) {
                if (!is_string($identifier) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $identifier)) {
                    throw new \InvalidArgumentException('Invalid schema identifier.');
                }
            }
            try { $db->select('SELECT ' . ($columns === [] ? '*' : implode(', ', array_map(static fn(string $column): string => "`{$column}`", $columns))) . " FROM {$table} LIMIT 0"); }
            catch (\PDOException $error) {
                if ($db->getPdo()->inTransaction() && !($installSchema && $db->getDriverName() === 'sqlite')) {
                    throw new \LogicException('Initialize complete schema before starting an application transaction.', 0, $error);
                }
                $exists = $db->getDriverName() === 'sqlite'
                    ? $db->selectOne("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?", [$table])
                    : $db->selectOne('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]);
                if ($exists !== null) { throw new \LogicException('Installed schema is incomplete or unavailable; explicit upgrade required.', 0, $error); }
                $missing = true;
            }
        }
        return !$missing;
    }

    /** Validate installed tables without issuing implicit-commit DDL in a MySQL transaction. */
    public static function useInstalledSchemaInTransaction(Connection $db, array $tables): bool
    {
        if ($db->getDriverName() !== 'mysql' || !$db->getPdo()->inTransaction()) {
            return false;
        }
        foreach ($tables as $table => $columns) {
            foreach (array_merge([$table], $columns) as $identifier) {
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $identifier)) {
                    throw new \InvalidArgumentException('Invalid schema identifier.');
                }
            }
            $projection = implode(', ', array_map(static fn(string $column): string => "`{$column}`", $columns));
            try {
                $db->select("SELECT {$projection} FROM {$table} LIMIT 0");
            } catch (\PDOException $error) {
                throw new \LogicException('Initialize complete schema before starting an application transaction.', 0, $error);
            }
        }
        return true;
    }

    public static function autoIncrement(PDO $pdo): string
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql' && $pdo->inTransaction()) {
            throw new \LogicException('Initialize schema before starting an application transaction.');
        }
        return match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'mysql' => 'INT AUTO_INCREMENT PRIMARY KEY',
            default => throw new \RuntimeException('Unsupported schema database driver.'),
        };
    }
}
