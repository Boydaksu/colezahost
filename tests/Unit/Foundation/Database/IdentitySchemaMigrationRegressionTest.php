<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Foundation\Database;

use Coleza\Domain\Identity\Rbac\RbacSchema;
use Coleza\Domain\Identity\Rbac\RbacService;
use Coleza\Domain\Installer\DatabaseSetupService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Database\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;

final class IdentitySchemaMigrationRegressionTest extends TestCase
{
    public function testLegacyNullableScopedAssignmentsArePreservedAndGlobalDuplicatesCollapse(): void
    {
        $db = new Connection(new PDO('sqlite::memory:'));
        $db->statement('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT UNIQUE, scope TEXT, description TEXT)');
        $db->statement('CREATE TABLE user_roles (user_id INT NOT NULL, role_id INT NOT NULL, organization_id INT NULL,
            PRIMARY KEY (user_id, role_id, organization_id))');
        $db->statement('CREATE TABLE role_permissions (role_id INT, permission_name TEXT, PRIMARY KEY(role_id, permission_name))');
        $db->statement('INSERT INTO roles VALUES (1, "billing", "system", "Billing"), (2, "member", "organization", "Member")');
        $db->statement('INSERT INTO role_permissions VALUES (1, "payments.manage"), (2, "org.services.view")');
        $db->statement('INSERT INTO user_roles VALUES (7, 1, NULL), (7, 1, NULL), (8, 2, 10)');
        $runner = new Migrator($db);
        self::assertCount(1, $runner->migrate(dirname(__DIR__, 4) . '/database/migrations'));
        self::assertSame([], $runner->migrate(dirname(__DIR__, 4) . '/database/migrations'));
        $rbac = new RbacService($db);
        self::assertTrue($rbac->hasPermission(7, 'payments.manage'));
        self::assertTrue($rbac->hasPermission(8, 'org.services.view', 10));
        self::assertFalse($rbac->hasPermission(8, 'org.services.view', 11));
        self::assertSame(2, (int) $db->selectOne('SELECT COUNT(*) AS n FROM user_roles')['n']);
    }

    public function testInterruptedCopyResumesFromOriginalAssignments(): void
    {
        $db = new Connection(new PDO('sqlite::memory:'));
        $db->statement('CREATE TABLE user_roles (user_id INT NOT NULL, role_id INT NOT NULL, PRIMARY KEY(user_id, role_id))');
        $db->statement('INSERT INTO user_roles VALUES (7, 1)');
        $db->statement('CREATE TABLE user_roles_d02_new (user_id INT NOT NULL, role_id INT NOT NULL, organization_id INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(user_id, role_id, organization_id))');
        $db->statement('INSERT INTO user_roles_d02_new (user_id, role_id, organization_id) VALUES (99, 99, 0)');
        (new RbacSchema($db))->upgrade();
        self::assertSame([['user_id' => 7, 'role_id' => 1, 'organization_id' => 0]],
            $db->select('SELECT user_id, role_id, organization_id FROM user_roles'));
    }

    public function testInvalidPermissionJsonCannotSilentlyGrantOrRecordSuccessfulMigration(): void
    {
        $db = new Connection(new PDO('sqlite::memory:'));
        (new DatabaseSetupService())->initializeCoreSchema($db);
        $db->statement('UPDATE roles SET permissions_json = ?', ['invalid-json']);
        $runner = new Migrator($db);
        try {
            $runner->migrate(dirname(__DIR__, 4) . '/database/migrations');
            self::fail('Invalid permission JSON must fail migration.');
        } catch (\JsonException) {
            self::assertSame([], $runner->getRanMigrations());
        }
    }

    public function testSequenceAllocationInsideTransactionRollsBackWithOwningRecord(): void
    {
        $db = new Connection(new PDO('sqlite::memory:'));
        $db->statement('CREATE TABLE seq (date_prefix TEXT PRIMARY KEY, last_number INT NOT NULL)');
        $db->beginTransaction();
        self::assertSame(1, $db->nextSequence('seq', 'date_prefix', '20261009'));
        self::assertTrue($db->inTransaction());
        $db->rollBack();
        self::assertSame(1, $db->nextSequence('seq', 'date_prefix', '20261009'));
        self::assertSame(2, $db->nextSequence('seq', 'date_prefix', '20261009'));
        self::assertSame(1, $db->nextSequence('seq', 'date_prefix', '20261010'));
    }
}
