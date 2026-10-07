<?php

declare(strict_types=1);

namespace Coleza\Foundation\Database;

use InvalidArgumentException;
use PDO;

final class ConnectionFactory
{
    /**
     * @param array{
     *     driver?: string,
     *     host?: string,
     *     port?: int,
     *     database?: string,
     *     username?: string,
     *     password?: string,
     *     charset?: string,
     *     options?: array<int, mixed>
     * } $config
     */
    public static function create(array $config): Connection
    {
        $driver = $config['driver'] ?? 'mysql';

        return match ($driver) {
            'mysql', 'mariadb' => self::createMySqlConnection($config),
            'sqlite' => self::createSqliteConnection($config),
            default => throw new InvalidArgumentException(sprintf('Unsupported database driver: %s', $driver)),
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function createMySqlConnection(array $config): Connection
    {
        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? 3306;
        $database = $config['database'] ?? '';
        $charset = $config['charset'] ?? 'utf8mb4';

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset);
        $username = $config['username'] ?? 'root';
        $password = $config['password'] ?? '';

        $options = $config['options'] ?? [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        $pdo = new PDO($dsn, $username, $password, $options);
        return new Connection($pdo);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function createSqliteConnection(array $config): Connection
    {
        $database = $config['database'] ?? ':memory:';
        $dsn = sprintf('sqlite:%s', $database);

        $options = $config['options'] ?? [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        $pdo = new PDO($dsn, null, null, $options);
        return new Connection($pdo);
    }
}
