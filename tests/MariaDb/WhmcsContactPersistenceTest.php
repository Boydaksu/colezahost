<?php

declare(strict_types=1);

namespace Coleza\Tests\MariaDb;

use Coleza\Domain\Migration\Adoption\AdoptedIdentityRepository;
use Coleza\Domain\Migration\Adoption\DomainAdoptionService;
use Coleza\Domain\Migration\Adoption\ProviderIdentityResolver;
use Coleza\Domain\Migration\Adoption\ServiceAdoptionService;
use Coleza\Domain\Migration\Mapping\GenericMappingEngine;
use Coleza\Domain\Migration\Staging\DatabaseStagingRepository;
use Coleza\Domain\Migration\Staging\StagingPipelineService;
use Coleza\Domain\Migration\Validation\CanonicalValidationEngine;
use Coleza\Domain\Migration\Whmcs\Core\WhmcsCoreEntityMigrator;
use Coleza\Domain\Migration\Whmcs\WhmcsReadOnlyConnector;
use Coleza\Tests\Support\MariaDbTestCase;

final class WhmcsContactPersistenceTest extends MariaDbTestCase
{
    public function testContactMembershipIsPortableRepeatableAndPreservesExistingRoles(): void
    {
        $repo = new DatabaseStagingRepository($this->db);
        $mapping = new GenericMappingEngine();
        $identities = new AdoptedIdentityRepository($this->db);
        $resolver = new ProviderIdentityResolver();
        $migrator = new WhmcsCoreEntityMigrator(
            $this->db, new WhmcsReadOnlyConnector($this->db), $repo,
            new StagingPipelineService($repo, $mapping, new CanonicalValidationEngine()),
            $mapping, $resolver, new ServiceAdoptionService($this->db, $identities, $resolver),
            new DomainAdoptionService($this->db, $identities, $resolver), $identities
        );
        $migrator->ensureTargetTables();
        $this->db->statement('CREATE TABLE tblclients (id INT PRIMARY KEY, firstname VARCHAR(50), lastname VARCHAR(50), companyname VARCHAR(100), email VARCHAR(191), currency INT, status VARCHAR(20), datecreated DATETIME)');
        $this->db->statement('CREATE TABLE tblcontacts (id INT PRIMARY KEY, userid INT, firstname VARCHAR(50), lastname VARCHAR(50), email VARCHAR(191), subaccount INT, password VARCHAR(255))');
        $this->db->statement("INSERT INTO tblclients VALUES (1, 'Alice', 'Smith', 'Smith Logistics', 'alice@example.test', 1, 'Active', '2025-01-01 12:00:00')");
        $hash = password_hash('test-only-random-fixture', PASSWORD_BCRYPT);
        $this->db->statement('INSERT INTO tblcontacts VALUES (11, 1, ?, ?, ?, 1, ?), (12, 1, ?, ?, ?, 1, ?)', ['New', 'Contact', 'new@example.test', $hash, 'Existing', 'Contact', 'existing@example.test', $hash]);
        $this->db->statement('INSERT INTO users (email, password_hash, name) VALUES (?, ?, ?)', ['existing@example.test', $hash, 'Preserved name']);
        $stats = $migrator->migrateClients('first');
        self::assertSame(1, $stats['migrated']);
        self::assertSame(2, (int) $this->db->selectOne("SELECT COUNT(*) n FROM organization_members WHERE role = 'member'")['n']);
        $this->db->statement("UPDATE organization_members SET role = 'admin' WHERE user_id = (SELECT id FROM users WHERE email = 'existing@example.test')");
        $migrator->migrateClients('second');
        self::assertSame(3, (int) $this->db->selectOne('SELECT COUNT(*) n FROM users')['n']);
        self::assertSame(3, (int) $this->db->selectOne('SELECT COUNT(*) n FROM organization_members')['n']);
        self::assertSame('admin', $this->db->selectOne("SELECT role FROM organization_members WHERE user_id = (SELECT id FROM users WHERE email = 'existing@example.test')")['role']);
        self::assertSame('Preserved name', $this->db->selectOne("SELECT name FROM users WHERE email = 'existing@example.test'")['name']);
        self::assertSame(2, (int) $this->db->selectOne('SELECT COUNT(*) n FROM tblcontacts')['n']);
    }
}
