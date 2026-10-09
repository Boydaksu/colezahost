<?php

declare(strict_types=1);

namespace Coleza\Tests\MariaDb;

use Coleza\Domain\Commerce\Invoices\InvoiceService;
use Coleza\Domain\Identity\Rbac\RbacService;
use Coleza\Domain\Installer\AdminBootstrapService;
use Coleza\Domain\Installer\AdminSetupDto;
use Coleza\Domain\Installer\DatabaseSetupService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Database\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class DatabaseFoundationTest extends TestCase
{
    private PDO $root;
    private Connection $db;
    private string $database;

    protected function setUp(): void
    {
        $dsn = getenv('COLEZA_TEST_MARIADB_DSN');
        if (!$dsn || !str_starts_with($dsn, 'mysql:')) {
            throw new \RuntimeException('An isolated MariaDB DSN is required; this suite never substitutes SQLite.');
        }
        $this->root = new PDO($dsn, getenv('COLEZA_TEST_MARIADB_USER'), getenv('COLEZA_TEST_MARIADB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $version = (string) $this->root->query('SELECT VERSION()')->fetchColumn();
        self::assertStringContainsString('10.11.', $version);
        self::assertStringContainsString('MariaDB', $version);
        $this->database = 'colezahost_d02_test_' . bin2hex(random_bytes(8));
        $this->root->exec('CREATE DATABASE `' . $this->database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->root->exec('USE `' . $this->database . '`');
        $this->db = new Connection($this->root);
    }

    protected function tearDown(): void
    {
        if (isset($this->root, $this->database) && preg_match('/^colezahost_d02_test_[a-f0-9]{16}$/D', $this->database)) {
            $this->root->exec('DROP DATABASE `' . $this->database . '`');
        }
    }

    public function testInvoiceNumbersUseMariaDbAndAdvance(): void
    {
        $invoices = new InvoiceService($this->db);
        $invoices->ensureTables();
        self::assertSame('INV-' . date('Y') . '-000001', $invoices->nextInvoiceNumber());
        self::assertSame('INV-' . date('Y') . '-000002', $invoices->nextInvoiceNumber());
    }

    public function testFreshInstallerAdminHasRbacPermissions(): void
    {
        (new DatabaseSetupService())->initializeCoreSchema($this->db);
        $admin = (new AdminBootstrapService())->bootstrapAdmin($this->db,
            new AdminSetupDto('test-admin@example.test', 'TestPassword123!', 'Test', 'Admin'));
        $rbac = new RbacService($this->db);
        self::assertTrue($rbac->hasPermission($admin['id'], 'payments.manage'));
        self::assertFalse($rbac->hasPermission(99999, 'payments.manage'));
    }

    public function testRbacCanAssignGlobalAndOrganizationRoles(): void
    {
        $rbac = new RbacService($this->db);
        $role = $rbac->findOrCreateRole('billing_admin');
        $rbac->grantPermission($role, 'payments.manage');
        $rbac->assignRole(7, 'billing_admin');
        $rbac->assignRole(7, 'billing_admin');
        self::assertTrue($rbac->hasPermission(7, 'payments.manage'));
        $orgRole = $rbac->findOrCreateRole('org_member', 'organization');
        $rbac->grantPermission($orgRole, 'org.services.view');
        $rbac->assignRole(8, 'org_member', 10);
        self::assertTrue($rbac->hasPermission(8, 'org.services.view', 10));
        self::assertFalse($rbac->hasPermission(8, 'org.services.view', 11));
        self::assertFalse($rbac->hasPermission(8, 'org.services.view'));
        self::assertSame(1, (int) $this->db->selectOne('SELECT COUNT(*) AS n FROM user_roles WHERE user_id = 7')['n']);
    }

    public function testProductionIdentityMigrationAppliesOnce(): void
    {
        $runner = new Migrator($this->db);
        $path = dirname(__DIR__, 2) . '/database/migrations';
        self::assertNotEmpty($runner->migrate($path));
        self::assertSame([], $runner->migrate($path));
        self::assertNotEmpty($runner->getRanMigrations());
        $rbac = new RbacService($this->db);
        self::assertGreaterThan(0, $rbac->findOrCreateRole('test-role'));
    }

    public static function sequenceServices(): array
    {
        return [
            [InvoiceService::class, 'nextInvoiceNumber', 'INV'],
            [\Coleza\Domain\Commerce\Payments\PaymentService::class, 'nextPaymentNumber', 'PAY'],
            [\Coleza\Domain\Commerce\Payments\PaymentService::class, 'nextRefundNumber', 'REF'],
            [\Coleza\Domain\Commerce\Services\ServiceService::class, 'nextServiceNumber', 'SRV'],
            [\Coleza\Domain\Commerce\Credit\CreditService::class, 'nextEntryNumber', 'CR'],
            [\Coleza\Domain\Finance\Accounts\FinancialAccountService::class, 'nextTransactionNumber', 'TXN'],
            [\Coleza\Domain\Finance\Expenses\ExpenseService::class, 'nextExpenseNumber', 'EXP'],
            [\Coleza\Domain\Finance\Profitability\ProfitabilityService::class, 'nextSettlementNumber', 'SET'],
        ];
    }

    #[DataProvider('sequenceServices')]
    public function testEveryFinancialSequenceRunsOnMariaDb(string $class, string $method, string $prefix): void
    {
        $service = new $class($this->db);
        $service->ensureTables();
        $first = $service->$method();
        $second = $service->$method();
        self::assertStringStartsWith($prefix . '-', $first);
        self::assertStringEndsWith('-000001', $first);
        self::assertStringEndsWith('-000002', $second);
    }

    public function testFourParallelConnectionsAllocateUniqueInvoiceNumbers(): void
    {
        (new InvoiceService($this->db))->ensureTables();
        $this->db->statement('CREATE TABLE worker_barrier (worker_id INT PRIMARY KEY, ready INT NOT NULL)');
        $this->db->statement('CREATE TABLE worker_control (id INT PRIMARY KEY, started INT NOT NULL)');
        $this->db->statement('INSERT INTO worker_control VALUES (1, 0)');
        $workers = [];
        try {
            for ($i = 0; $i < 4; $i++) {
                $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/sequence-worker.php', $this->database, (string) $i],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                fclose($pipes[0]);
                $workers[] = [$process, $pipes];
            }
            $deadline = microtime(true) + 15;
            do {
                $ready = (int) $this->db->selectOne('SELECT COUNT(*) AS n FROM worker_barrier')['n'];
                if ($ready === 4) { break; }
                usleep(10000);
            } while (microtime(true) < $deadline);
            self::assertSame(4, $ready, 'All independent processes must reach the barrier.');
            $this->db->statement('UPDATE worker_control SET started = 1 WHERE id = 1');
            $numbers = [];
            foreach ($workers as [$process, $pipes]) {
                stream_set_timeout($pipes[1], 20);
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                self::assertSame('', $error);
                $values = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
                self::assertCount(64, $values);
                array_push($numbers, ...$values);
            }
            self::assertCount(256, $numbers);
            self::assertCount(256, array_unique($numbers));
            sort($numbers);
            self::assertSame('INV-' . date('Y') . '-000001', $numbers[0]);
            self::assertSame('INV-' . date('Y') . '-000256', $numbers[255]);
        } finally {
            foreach ($workers as [$process, $pipes]) {
                foreach ([1, 2] as $index) { fclose($pipes[$index]); }
                proc_terminate($process);
                proc_close($process);
            }
        }
    }

    public function testLegacyInstallerRolesAndPermissionsSurviveMigration(): void
    {
        $this->db->statement('CREATE TABLE roles (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) UNIQUE,
            display_name VARCHAR(100) NOT NULL, permissions_json TEXT, is_system INT DEFAULT 1)');
        $this->db->statement('CREATE TABLE user_roles (user_id INT NOT NULL, role_id INT NOT NULL, created_at TIMESTAMP, PRIMARY KEY (user_id, role_id))');
        $this->db->statement('INSERT INTO roles VALUES (1, "super_admin", "Super Administrator", ?, 1)', ['["*"]']);
        $this->db->statement('INSERT INTO roles VALUES (2, "legacy_billing", "Billing", ?, 1)', ['["invoices.view"]']);
        $this->db->statement('INSERT INTO user_roles VALUES (7, 1, "2025-01-02 03:04:05"), (8, 2, "2025-02-03 04:05:06")');
        $runner = new Migrator($this->db);
        self::assertCount(2, $runner->migrate(dirname(__DIR__, 2) . '/database/migrations'));
        $rbac = new RbacService($this->db);
        self::assertTrue($rbac->hasPermission(7, 'payments.manage'));
        self::assertTrue($rbac->hasPermission(8, 'invoices.view'));
        self::assertFalse($rbac->hasPermission(8, 'payments.manage'));
        self::assertGreaterThan(2, $rbac->findOrCreateRole('new-role'));
        self::assertSame('2025-01-02 03:04:05', $this->db->selectOne('SELECT created_at FROM user_roles WHERE user_id = 7')['created_at']);
        self::assertSame([], $runner->migrate(dirname(__DIR__, 2) . '/database/migrations'));
    }

    public function testPartialDdlFailureIsNotRecordedAsSuccessfulAndCanResume(): void
    {
        $runner = new Migrator($this->db);
        try {
            $runner->migrate(__DIR__ . '/fixtures/fail-once');
            self::fail('Expected partial DDL failure.');
        } catch (\RuntimeException $error) {
            self::assertSame('Simulated failure after committed DDL', $error->getMessage());
        }
        self::assertFalse($this->db->inTransaction());
        self::assertSame([], $runner->getRanMigrations());
        self::assertSame(['001_partial_ddl'], $runner->migrate(__DIR__ . '/fixtures/fail-once'));
        self::assertSame(['001_partial_ddl'], $runner->getRanMigrations());
        self::assertSame(['001_partial_ddl'], $runner->rollback(__DIR__ . '/fixtures/fail-once'));
        self::assertSame([], $runner->getRanMigrations());
    }

    public function testPrefixedInstallerSchemaUsesTheSameRbacContract(): void
    {
        (new DatabaseSetupService())->initializeCoreSchema($this->db, 'cz_');
        $admin = (new AdminBootstrapService())->bootstrapAdmin($this->db,
            new AdminSetupDto('test-admin@example.test', 'TestPassword123!', 'Test', 'Admin'), 'cz_');
        self::assertTrue((new RbacService($this->db, 'cz_'))->hasPermission($admin['id'], 'payments.manage'));
    }
}
