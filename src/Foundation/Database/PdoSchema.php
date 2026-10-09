<?php

declare(strict_types=1);

namespace Coleza\Foundation\Database;

use PDO;

final class PdoSchema
{
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
