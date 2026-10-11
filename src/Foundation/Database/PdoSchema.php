<?php

declare(strict_types=1);

namespace Coleza\Foundation\Database;

use PDO;

final class PdoSchema
{
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
            $projection = implode(', ', $columns);
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
