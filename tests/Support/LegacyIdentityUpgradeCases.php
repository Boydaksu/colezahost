<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;

use Coleza\Domain\Installer\DatabaseSetupService;
use Coleza\Domain\Installer\AdminBootstrapService;
use Coleza\Domain\Installer\AdminSetupDto;

trait LegacyIdentityUpgradeCases
{
    public function testLegacyUsersOrganizationsAndMembershipsSurviveRepeatedUpgrade(): void
    {
        $id = $this->db->getDriverName() === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $this->db->statement("CREATE TABLE users (id {$id}, email VARCHAR(191) UNIQUE, password_hash VARCHAR(255) NOT NULL, name VARCHAR(100) NOT NULL, is_active INT, created_at TIMESTAMP)");
        $this->db->statement("CREATE TABLE organizations (id {$id}, name VARCHAR(150) NOT NULL, slug VARCHAR(100) UNIQUE, owner_user_id INT NOT NULL, created_at TIMESTAMP)");
        $this->db->statement("CREATE TABLE organization_members (id {$id}, organization_id INT, user_id INT, role VARCHAR(50), UNIQUE(organization_id, user_id))");
        $hash = password_hash('FixturePassword123!', PASSWORD_BCRYPT);
        $this->db->statement('INSERT INTO users VALUES (7, ?, ?, ?, 0, ?), (8, ?, ?, ?, 1, ?)', ['inactive@example.test', $hash, 'İnaktif müşteri', '2025-01-01 12:00:00', 'active@example.test', $hash, 'Aktif müşteri', '2025-02-01 12:00:00']);
        $this->db->statement("INSERT INTO organizations VALUES (10, 'Müşteri şirketi', 'customer', 7, '2025-01-01 12:00:00')");
        $this->db->statement("INSERT INTO organization_members VALUES (20, 10, 7, 'owner')");
        $users = $this->db->select('SELECT * FROM users ORDER BY id');
        $orgs = $this->db->select('SELECT * FROM organizations');
        $members = $this->db->select('SELECT * FROM organization_members');
        (new DatabaseSetupService())->initializeApplicationSchema($this->db);
        (new DatabaseSetupService())->initializeApplicationSchema($this->db);
        foreach ($users as $before) {
            $after = $this->db->selectOne('SELECT * FROM users WHERE id = ?', [$before['id']]);
            self::assertSame($before, array_intersect_key($after, $before));
            self::assertSame((int) $before['is_active'] === 1 ? 'active' : 'inactive', $after['status']);
            self::assertArrayHasKey('first_name', $after);
            self::assertArrayHasKey('updated_at', $after);
        }
        $afterOrg = $this->db->selectOne('SELECT * FROM organizations WHERE id = 10');
        self::assertSame($orgs[0], array_intersect_key($afterOrg, $orgs[0]));
        self::assertSame('USD', $afterOrg['billing_currency']);
        self::assertSame($members, $this->db->select('SELECT * FROM organization_members'));
        $admin = (new AdminBootstrapService())->bootstrapAdmin($this->db, new AdminSetupDto('admin@example.test', 'NewAdminPassword123!', 'Coleza', 'Admin'));
        $row = $this->db->selectOne('SELECT * FROM users WHERE id = ?', [$admin['id']]);
        self::assertSame('Coleza Admin', $row['name']);
        self::assertSame(1, (int) $row['is_active']);
        self::assertSame(0, (int) $this->db->selectOne('SELECT is_active FROM users WHERE id = 7')['is_active']);
        $auth = new \Coleza\Domain\Identity\Auth\AuthService($this->db, new \Coleza\Domain\Identity\Security\PasswordHasher(), new \Coleza\Domain\Identity\Session\DatabaseSessionHandler($this->db));
        try {
            $auth->authenticate('inactive@example.test', 'FixturePassword123!');
            self::fail('Upgrade must not make the inactive account usable.');
        } catch (\Coleza\Foundation\Exceptions\ValidationException $error) {
            self::assertStringContainsString('inactive', $error->getMessage());
        }
    }

    public function testStatusOnlyLegacySchemaDoesNotReactivateDisabledUsers(): void
    {
        $id = $this->db->getDriverName() === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $this->db->statement("CREATE TABLE users (id {$id}, email VARCHAR(191) UNIQUE, password_hash VARCHAR(255), first_name VARCHAR(100), last_name VARCHAR(100), status VARCHAR(50))");
        $this->db->statement("INSERT INTO users VALUES (7, 'disabled@example.test', 'preserved-hash', 'Ayşe', 'Yılmaz', 'suspended'), (8, 'active@example.test', 'other-hash', 'Ali', 'Kaya', 'active')");
        (new DatabaseSetupService())->initializeApplicationSchema($this->db);
        (new DatabaseSetupService())->initializeApplicationSchema($this->db);
        $row = $this->db->selectOne('SELECT * FROM users WHERE id = 7');
        self::assertSame(0, (int) $row['is_active']);
        self::assertSame('suspended', $row['status']);
        self::assertSame('preserved-hash', $row['password_hash']);
        self::assertSame('Ayşe Yılmaz', $row['name']);
        self::assertSame(1, (int) $this->db->selectOne('SELECT is_active FROM users WHERE id = 8')['is_active']);
    }

    public function testUnknownAccessSchemaStopsBeforeAnyMutation(): void
    {
        $this->db->statement('CREATE TABLE users (id INT PRIMARY KEY, email VARCHAR(191), password_hash VARCHAR(255))');
        $this->db->statement("INSERT INTO users VALUES (7, 'unknown@example.test', 'preserved')");
        $caught = null;
        try {
            (new DatabaseSetupService())->initializeCoreSchema($this->db);
        } catch (\RuntimeException $error) {
            $caught = $error;
        }
        self::assertNotNull($caught, 'Unknown account access state must require reconciliation.');
        self::assertStringContainsString('access state', $caught->getMessage());
        self::assertSame([['id' => 7, 'email' => 'unknown@example.test', 'password_hash' => 'preserved']], $this->db->select('SELECT * FROM users'));
        $tables = $this->db->getDriverName() === 'mysql' ? $this->db->select('SHOW TABLES') : $this->db->select("SELECT name FROM sqlite_master WHERE type = 'table'");
        self::assertCount(1, $tables);
    }

    public function testConflictingAccessFlagsAreTightenedAndOriginalStatusIsPreserved(): void
    {
        $this->db->statement('CREATE TABLE users (id INT PRIMARY KEY, email VARCHAR(191), password_hash VARCHAR(255), status VARCHAR(50), is_active INT)');
        $this->db->statement("INSERT INTO users VALUES (7, 'suspended@example.test', 'preserved', 'suspended', 1)");
        (new DatabaseSetupService())->initializeCoreSchema($this->db);
        $row = $this->db->selectOne('SELECT * FROM users WHERE id = 7');
        self::assertSame('suspended', $row['status']);
        self::assertSame(0, (int) $row['is_active']);
        self::assertSame('preserved', $row['password_hash']);
    }

    public function testPartialUpgradeCanResumeWithoutActivatingAnInactiveAccount(): void
    {
        $id = $this->db->getDriverName() === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $this->db->statement("CREATE TABLE users (id {$id}, email VARCHAR(191), password_hash VARCHAR(255), name VARCHAR(100), is_active INT)");
        $this->db->statement("CREATE TABLE organizations (id {$id}, name VARCHAR(150), slug VARCHAR(100), owner_user_id INT)");
        $this->db->statement("INSERT INTO users VALUES (7, 'inactive@example.test', 'preserved', 'Preserved name', 0)");
        $trigger = $this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER reject_identity_upgrade BEFORE UPDATE ON users FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected upgrade failure'"
            : "CREATE TRIGGER reject_identity_upgrade BEFORE UPDATE ON users BEGIN SELECT RAISE(ABORT, 'Injected upgrade failure'); END";
        $this->db->statement($trigger);
        $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_10_11_000007_legacy_identity_columns.php';
        try {
            $migration->up($this->db);
            self::fail('Injected failure must reach the migration caller.');
        } catch (\PDOException $error) {
            self::assertStringContainsString('Injected upgrade failure', $error->getMessage());
        }
        self::assertSame(0, (int) $this->db->selectOne('SELECT is_active FROM users WHERE id = 7')['is_active']);
        self::assertFalse($this->db->inTransaction());
        $this->db->statement('DROP TRIGGER reject_identity_upgrade');
        $migration->up($this->db);
        $migration->up($this->db);
        $row = $this->db->selectOne('SELECT * FROM users WHERE id = 7');
        self::assertSame('inactive', $row['status']);
        self::assertSame('preserved', $row['password_hash']);
        self::assertSame('Preserved name', $row['name']);
    }
}
