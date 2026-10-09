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
        $identitySchema = new \Coleza\Domain\Identity\Rbac\RbacSchema($connection, $prefix);
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
                name VARCHAR(200) NULL,
                first_name VARCHAR(100) NULL,
                last_name VARCHAR(100) NULL,
                status VARCHAR(50) NOT NULL DEFAULT "active",
                is_active INT NOT NULL DEFAULT 1,
                email_verified_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL
            )',
            $tUsers,
            $autoInc
        ));
        $tables[] = $tUsers;

        // Shared canonical RBAC schema, including upgrade of legacy installer tables.
        $identitySchema->upgrade();
        $rbac = new \Coleza\Domain\Identity\Rbac\RbacService($connection, $prefix);
        $roleId = $rbac->findOrCreateRole('super_admin', 'system', 'Super Administrator');
        $rbac->grantPermission($roleId, '*');
        $connection->statement(sprintf('UPDATE %sroles SET display_name = ? WHERE id = ?', $prefix), ['Super Administrator', $roleId]);
        array_push($tables, $prefix . 'roles', $prefix . 'permissions', $prefix . 'role_permissions', $prefix . 'user_roles');

        // 4. Organizations table
        $tOrgs = $prefix . 'organizations';
        $connection->statement(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                name VARCHAR(255) NOT NULL,
                slug VARCHAR(255) NOT NULL UNIQUE,
                owner_user_id INT NULL,
                status VARCHAR(50) NOT NULL DEFAULT "active",
                billing_currency VARCHAR(3) NOT NULL DEFAULT "USD",
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $tOrgs,
            $autoInc
        ));
        $tables[] = $tOrgs;

        // 4b. Organization Members table
        $tOrgMembers = $prefix . 'organization_members';
        $connection->statement(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                organization_id INT NOT NULL,
                user_id INT NOT NULL,
                role VARCHAR(50) NOT NULL DEFAULT "member",
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(organization_id, user_id)
            )',
            $tOrgMembers,
            $autoInc
        ));
        $tables[] = $tOrgMembers;

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
