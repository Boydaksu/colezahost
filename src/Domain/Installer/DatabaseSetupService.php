<?php

declare(strict_types=1);

namespace Coleza\Domain\Installer;

use Coleza\Foundation\Database\Connection;
use PDO;
use Throwable;

/**
 * Validates database connectivity, server engine version, and installs baseline schema.
 */
final class DatabaseSetupService
{
    /**
     * Attempts a connection using the provided database configuration.
     */
    public function testConnection(DatabaseConfig $config): ConnectionTestResult
    {
        try {
            $pdo = $this->createPdo($config);
            $version = (string) $pdo->query('SELECT 1')->fetchColumn();
            $serverVersion = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

            return ConnectionTestResult::successful($serverVersion, [
                'driver' => $config->getDriver(),
                'database' => $config->getDatabase(),
            ]);
        } catch (Throwable $e) {
            return ConnectionTestResult::failed($e->getMessage(), [
                'driver' => $config->getDriver(),
                'host' => $config->getHost(),
                'database' => $config->getDatabase(),
            ]);
        }
    }

    /**
     * Creates a Connection instance from DatabaseConfig.
     */
    public function createConnection(DatabaseConfig $config): Connection
    {
        $pdo = $this->createPdo($config);
        return new Connection($pdo, $config->getDriver());
    }

    /**
     * Initializes all foundational schema tables required for application operation.
     *
     * @return array{success: bool, tables_initialized: list<string>}
     */
    public function initializeCoreSchema(Connection $connection, string $prefix = ''): array
    {
        $driver = $connection->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        $tables = [];

        // 1. Users table
        $tUsers = $prefix . 'users';
        $connection->statement(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                email VARCHAR(255) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                first_name VARCHAR(100) NOT NULL,
                last_name VARCHAR(100) NOT NULL,
                status VARCHAR(50) NOT NULL DEFAULT "active",
                email_verified_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL
            )',
            $tUsers,
            $autoInc
        ));
        $tables[] = $tUsers;

        // 2. Roles table
        $tRoles = $prefix . 'roles';
        $connection->statement(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                name VARCHAR(50) NOT NULL UNIQUE,
                display_name VARCHAR(100) NOT NULL,
                permissions_json TEXT NULL,
                is_system INT NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $tRoles,
            $autoInc
        ));
        $tables[] = $tRoles;

        // Seed default SUPER_ADMIN role if empty
        $existingAdminRole = $connection->selectOne(sprintf('SELECT id FROM %s WHERE name = ?', $tRoles), ['super_admin']);
        if ($existingAdminRole === null) {
            $connection->statement(
                sprintf('INSERT INTO %s (name, display_name, permissions_json, is_system) VALUES (?, ?, ?, 1)', $tRoles),
                ['super_admin', 'Super Administrator', json_encode(['*'])]
            );
        }

        // 3. User Roles mapping table
        $tUserRoles = $prefix . 'user_roles';
        $connection->statement(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                user_id INT NOT NULL,
                role_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, role_id)
            )',
            $tUserRoles
        ));
        $tables[] = $tUserRoles;

        // 4. Organizations table
        $tOrgs = $prefix . 'organizations';
        $connection->statement(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                name VARCHAR(255) NOT NULL,
                slug VARCHAR(255) NOT NULL UNIQUE,
                status VARCHAR(50) NOT NULL DEFAULT "active",
                billing_currency VARCHAR(3) NOT NULL DEFAULT "USD",
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $tOrgs,
            $autoInc
        ));
        $tables[] = $tOrgs;

        // 5. Brands table
        $tBrands = $prefix . 'brands';
        $connection->statement(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                name VARCHAR(100) NOT NULL,
                code VARCHAR(50) NOT NULL UNIQUE,
                domain VARCHAR(191) NULL,
                default_locale VARCHAR(10) NOT NULL DEFAULT "en",
                default_currency VARCHAR(3) NOT NULL DEFAULT "USD",
                is_active INT NOT NULL DEFAULT 1,
                settings LONGTEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $tBrands,
            $autoInc
        ));
        $tables[] = $tBrands;

        // 6. System Settings table
        $tSettings = $prefix . 'system_settings';
        $connection->statement(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                setting_key VARCHAR(100) NOT NULL UNIQUE,
                setting_value TEXT NULL,
                is_encrypted INT NOT NULL DEFAULT 0,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $tSettings,
            $autoInc
        ));
        $tables[] = $tSettings;

        // 7. Cron Runs table
        $tCron = $prefix . 'cron_runs';
        $connection->statement(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                run_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                duration_ms INT NOT NULL DEFAULT 0,
                status VARCHAR(32) NOT NULL DEFAULT "success",
                output TEXT NULL
            )',
            $tCron,
            $autoInc
        ));
        $tables[] = $tCron;

        return [
            'success' => true,
            'tables_initialized' => $tables,
        ];
    }

    private function createPdo(DatabaseConfig $config): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        if ($config->getDriver() === 'sqlite') {
            return new PDO($config->buildDsn(), null, null, $options);
        }

        return new PDO(
            $config->buildDsn(),
            $config->getUsername(),
            $config->getPassword(),
            $options
        );
    }
}
