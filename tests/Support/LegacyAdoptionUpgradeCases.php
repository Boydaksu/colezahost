<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;

trait LegacyAdoptionUpgradeCases
{
    private function legacyService(): array
    {
        $id = \Coleza\Foundation\Database\PdoSchema::autoIncrement($this->db->getPdo());
        $this->db->statement("CREATE TABLE services (id {$id}, user_id INT NOT NULL, product_id INT NOT NULL, server_id INT NULL,
            domain VARCHAR(255) NULL, username VARCHAR(64) NULL, status VARCHAR(32) NOT NULL DEFAULT 'active',
            billing_cycle VARCHAR(32) NOT NULL DEFAULT 'monthly', recurring_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            currency VARCHAR(3) NOT NULL DEFAULT 'USD', registration_date VARCHAR(30) NULL, next_due_date VARCHAR(30) NULL,
            is_adopted TINYINT(1) NOT NULL DEFAULT 1, metadata_json TEXT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
        $this->db->statement("INSERT INTO services (id,user_id,product_id,server_id,domain,username,status,billing_cycle,recurring_amount,currency,registration_date,next_due_date,is_adopted,metadata_json,created_at)
            VALUES (11,7,5,3,'example.test','legacy-user','suspended','monthly',19.99,'TRY','2025-01-02','2026-12-02',1,?,'2025-01-02 03:04:05')", [json_encode(['note' => 'İçerik'], JSON_UNESCAPED_UNICODE)]);
        return $this->db->select('SELECT * FROM services ORDER BY id');
    }
    private function legacyDomain(): array
    {
        $id = \Coleza\Foundation\Database\PdoSchema::autoIncrement($this->db->getPdo());
        $this->db->statement("CREATE TABLE domains (id {$id},user_id INT NOT NULL,domain_name VARCHAR(255) NOT NULL UNIQUE,
            registrar VARCHAR(64) NOT NULL,status VARCHAR(32) NOT NULL DEFAULT 'active',registration_period_years INT NOT NULL DEFAULT 1,
            recurring_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,currency VARCHAR(3) NOT NULL DEFAULT 'USD',registration_date VARCHAR(30) NULL,
            expiry_date VARCHAR(30) NULL,next_due_date VARCHAR(30) NULL,auto_renew TINYINT(1) NOT NULL DEFAULT 1,id_protection TINYINT(1) NOT NULL DEFAULT 0,
            nameservers_json TEXT NULL,is_adopted TINYINT(1) NOT NULL DEFAULT 1,metadata_json TEXT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
        $this->db->statement("INSERT INTO domains (id,user_id,domain_name,registrar,status,registration_period_years,recurring_amount,currency,registration_date,expiry_date,next_due_date,auto_renew,id_protection,nameservers_json,is_adopted,metadata_json,created_at)
            VALUES (22,7,'Example.test','manual','active',1,9.99,'USD','2025-01-02','2026-12-02','2026-11-02',0,1,?,1,?,'2025-01-02 03:04:05')", [json_encode(['ns1.example.test']), json_encode(['note' => 'Domain içeriği'], JSON_UNESCAPED_UNICODE)]);
        return $this->db->select('SELECT * FROM domains ORDER BY id');
    }
    public function testInstallerUpgradesLegacyServiceAndPreservesOriginalAndPlacement(): void
    {
        $before = $this->legacyService();
        (new \Coleza\Domain\Installer\DatabaseSetupService())->initializeApplicationSchema($this->db);
        $services = new \Coleza\Domain\Commerce\Services\ServiceService($this->db);
        $loaded = $services->findServiceById(11);
        self::assertSame(1999, $loaded->getRecurringAmountMinor());
        self::assertSame('TRY', $loaded->getCurrencyCode());
        self::assertSame('suspended', $loaded->getStatus());
        self::assertSame('2026-12-02', $loaded->getNextDueDate());
        self::assertSame('İçerik', $loaded->getMetadata()['note']);
        self::assertSame(0, (int) $this->db->selectOne('SELECT auto_renew FROM services WHERE id = 11')['auto_renew']);
        self::assertSame(3, $services->getPlacementForService(11)->getServerId());
        self::assertSame($before, $this->db->select('SELECT * FROM legacy_adoption_services_v12 ORDER BY id'));
        $new = $services->createService(['user_id' => 7, 'product_id' => 5, 'registration_date' => '2026-01-01', 'next_due_date' => '2026-12-01']);
        self::assertGreaterThan(11, $new->getId());
    }
    public function testInstallerUpgradesLegacyDomainAndAllowsNewApplicationRecord(): void
    {
        $before = $this->legacyDomain();
        (new \Coleza\Domain\Installer\DatabaseSetupService())->initializeApplicationSchema($this->db);
        $domains = new \Coleza\Domain\Domains\DomainService($this->db, null, \Coleza\Tests\Support\SecretFixtureKey::encryptor());
        $loaded = $domains->findDomainByName('example.test');
        self::assertSame(22, $loaded->getId());
        self::assertSame('manual', $loaded->getRegistrarId());
        self::assertSame('2026-12-02', $loaded->getExpiryDate());
        self::assertSame(['ns1.example.test'], $loaded->getNameservers());
        self::assertTrue($loaded->isWhoisPrivacy());
        self::assertFalse($loaded->isAutoRenew());
        self::assertSame(9.99, $loaded->getMetadata()['adopted_billing']['recurring_amount']);
        self::assertSame($before, $this->db->select('SELECT * FROM legacy_adoption_domains_v12 ORDER BY id'));
        self::assertGreaterThan(22, $domains->createDomain(['user_id' => 7, 'domain' => 'new.test'])->getId());
    }
    public function testRepeatedUpgradeDoesNotOverwriteLaterServiceChanges(): void
    {
        $before = $this->legacyService();
        $setup = new \Coleza\Domain\Installer\DatabaseSetupService();
        $setup->initializeApplicationSchema($this->db);
        $this->db->statement('UPDATE services SET recurring_amount_minor = 4999 WHERE id = 11');
        $current = $this->db->select('SELECT * FROM services');
        $setup->initializeApplicationSchema($this->db);
        self::assertSame($current, $this->db->select('SELECT * FROM services'));
        self::assertSame($before, $this->db->select('SELECT * FROM legacy_adoption_services_v12'));
        self::assertCount(1, $this->db->select('SELECT * FROM service_placements'));
    }
    public function testInvalidLegacyAmountStopsBothTablesBeforeRenaming(): void
    {
        $this->legacyService();
        $domains = $this->legacyDomain();
        $this->db->statement('UPDATE services SET recurring_amount = -19.99 WHERE id = 11');
        $services = $this->db->select('SELECT * FROM services');
        $this->expectSafeLegacyStop();
        self::assertSame($services, $this->db->select('SELECT * FROM services'));
        self::assertSame($domains, $this->db->select('SELECT * FROM domains'));
    }
    public function testMissingLegacyDateIsNotInvented(): void
    {
        $this->legacyService();
        $this->db->statement('UPDATE services SET next_due_date = NULL WHERE id = 11');
        $before = $this->db->select('SELECT * FROM services');
        $this->expectSafeLegacyStop();
        self::assertSame($before, $this->db->select('SELECT * FROM services'));
    }
    public function testDeletedLegacyIdsAreNotReused(): void
    {
        $this->legacyService();
        $this->db->statement("INSERT INTO services (id,user_id,product_id,status,billing_cycle,recurring_amount,currency,registration_date,next_due_date) VALUES (900,7,5,'active','monthly',1,'USD','2025-01-01','2026-12-01')");
        $this->db->statement('DELETE FROM services WHERE id = 900');
        (new \Coleza\Domain\Installer\DatabaseSetupService())->initializeApplicationSchema($this->db);
        $new = (new \Coleza\Domain\Commerce\Services\ServiceService($this->db))->createService(['user_id' => 7, 'product_id' => 5]);
        self::assertGreaterThan(900, $new->getId());
        self::assertSame(901, (int) $this->db->selectOne("SELECT source_next_id FROM legacy_adoption_upgrades WHERE entity = 'services'")['source_next_id']);
    }
    public function testIdentityLinksKeepTheirTargetIdsAndFields(): void
    {
        $this->legacyService();
        $repo = new \Coleza\Domain\Migration\Adoption\AdoptedIdentityRepository($this->db);
        $repo->ensureTable();
        $identity = new \Coleza\Domain\Migration\Adoption\AdoptedProviderIdentity(null, 'old-batch', \Coleza\Domain\Migration\Adoption\AdoptedIdentityType::HOSTING_SERVICE, 'legacy-service', 'whmcs', 11, 'server', '3', 'legacy-user', remoteVerificationPassed: false, suppressProvisioning: true);
        $repo->save($identity);
        $before = $this->db->select('SELECT * FROM adopted_provider_identities');
        (new \Coleza\Domain\Installer\DatabaseSetupService())->initializeApplicationSchema($this->db);
        self::assertSame($before, $this->db->select('SELECT * FROM adopted_provider_identities'));
        self::assertSame(11, $repo->findByExternalReference(\Coleza\Domain\Migration\Adoption\AdoptedIdentityType::HOSTING_SERVICE, '3', 'legacy-user')->getTargetEntityId());
        $adopter = new \Coleza\Domain\Migration\Adoption\ServiceAdoptionService($this->db, $repo,
            new \Coleza\Domain\Migration\Adoption\ProviderIdentityResolver(serverMap: ['old' => 3], productMap: ['product' => 5], clientMap: ['client' => 7]));
        $result = $adopter->adoptService(new \Coleza\Domain\Migration\Canonical\CanonicalServiceDto('legacy-service', 'whmcs', 'client', 'product', 'example.test', 'legacy-user', registrationDate: '2025-01-02', nextDueDate: '2026-12-02'), 'new-batch', 'old');
        self::assertSame(11, $result->getAdoptedServiceId());
        self::assertCount(1, $this->db->select('SELECT * FROM services'));
    }
    public function testNormalizedDomainCollisionStopsBeforeDdl(): void
    {
        $this->legacyDomain();
        $this->db->statement("INSERT INTO domains (user_id,domain_name,registrar) VALUES (8,' example.test ','manual')");
        $before = $this->db->select('SELECT * FROM domains ORDER BY id');
        $this->expectSafeLegacyStop();
        self::assertSame($before, $this->db->select('SELECT * FROM domains ORDER BY id'));
    }
    public function testCustomIndexStopsWithoutDroppingIt(): void
    {
        $before = $this->legacyService();
        $this->db->statement('CREATE INDEX custom_legacy_service_user ON services (user_id)');
        $this->expectSafeLegacyStop();
        self::assertSame($before, $this->db->select('SELECT * FROM services'));
    }
    public function testInterruptedCopyCanResumeWithoutDuplicateRowsOrLostArchive(): void
    {
        $before = $this->legacyService();
        $this->db->statement('CREATE TABLE legacy_adoption_upgrades (entity VARCHAR(16) PRIMARY KEY, source_checksum VARCHAR(64) NOT NULL, source_count INT NOT NULL, source_next_id INT NOT NULL, status VARCHAR(16) NOT NULL, completed_at VARCHAR(32) NULL)');
        $trigger = $this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER reject_upgrade_complete BEFORE UPDATE ON legacy_adoption_upgrades FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture completion failure'"
            : "CREATE TRIGGER reject_upgrade_complete BEFORE UPDATE ON legacy_adoption_upgrades BEGIN SELECT RAISE(ABORT, 'fixture completion failure'); END";
        $this->db->statement($trigger);
        $setup = new \Coleza\Domain\Installer\DatabaseSetupService();
        $caught = null;
        try { $setup->initializeApplicationSchema($this->db); } catch (\PDOException $error) { $caught = $error; }
        self::assertNotNull($caught);
        self::assertSame($before, $this->db->select('SELECT * FROM legacy_adoption_services_v12'));
        self::assertSame([], $this->db->select('SELECT * FROM services'));
        self::assertSame([], $this->db->select('SELECT * FROM service_placements'));
        self::assertSame('copying', $this->db->selectOne("SELECT status FROM legacy_adoption_upgrades WHERE entity = 'services'")['status']);
        $this->db->statement('DROP TRIGGER reject_upgrade_complete');
        $setup->initializeApplicationSchema($this->db);
        self::assertSame(1999, (int) $this->db->selectOne('SELECT recurring_amount_minor FROM services WHERE id = 11')['recurring_amount_minor']);
        self::assertSame('complete', $this->db->selectOne("SELECT status FROM legacy_adoption_upgrades WHERE entity = 'services'")['status']);
        self::assertCount(1, $this->db->select('SELECT * FROM services'));
        self::assertCount(1, $this->db->select('SELECT * FROM service_placements'));
    }
    public function testExplicitLegacyRenewalAndGraceFlagsArePreserved(): void
    {
        $this->legacyService();
        foreach (['auto_renew' => 1, 'grace_period_days' => 12, 'termination_grace_period_days' => 44, 'lock_version' => 8] as $column => $value) {
            $this->db->statement("ALTER TABLE services ADD COLUMN {$column} INT NOT NULL DEFAULT {$value}");
        }
        $before = $this->db->select('SELECT * FROM services');
        (new \Coleza\Domain\Installer\DatabaseSetupService())->initializeApplicationSchema($this->db);
        $row = $this->db->selectOne('SELECT * FROM services WHERE id = 11');
        self::assertSame(1, (int) $row['auto_renew']);
        self::assertSame(12, (int) $row['grace_period_days']);
        self::assertSame(44, (int) $row['termination_grace_period_days']);
        self::assertSame(8, (int) $row['lock_version']);
        self::assertSame($before, $this->db->select('SELECT * FROM legacy_adoption_services_v12'));
    }
    public function testConfiguredNonTwoDecimalCurrencyStopsBeforeConversion(): void
    {
        $this->legacyService();
        $this->db->statement('CREATE TABLE currencies (code VARCHAR(3) PRIMARY KEY, decimal_places INT NOT NULL)');
        $this->db->statement("INSERT INTO currencies VALUES ('TRY', 0)");
        $before = $this->db->select('SELECT * FROM services');
        $this->expectSafeLegacyStop();
        self::assertSame($before, $this->db->select('SELECT * FROM services'));
    }
    public function testMissingIdentityDoesNotCreateDuplicateServiceOnReimport(): void
    {
        $this->legacyService();
        (new \Coleza\Domain\Installer\DatabaseSetupService())->initializeApplicationSchema($this->db);
        $adopter = new \Coleza\Domain\Migration\Adoption\ServiceAdoptionService($this->db,
            new \Coleza\Domain\Migration\Adoption\AdoptedIdentityRepository($this->db),
            new \Coleza\Domain\Migration\Adoption\ProviderIdentityResolver(serverMap: ['old' => 3], productMap: ['product' => 5], clientMap: ['client' => 7]));
        $result = $adopter->adoptService(new \Coleza\Domain\Migration\Canonical\CanonicalServiceDto('unknown-source', 'whmcs', 'client', 'product', 'example.test', 'legacy-user', registrationDate: '2025-01-02', nextDueDate: '2026-12-02'), 'new-batch', 'old');
        self::assertFalse($result->isSuccess());
        self::assertCount(1, $this->db->select('SELECT * FROM services'));
        self::assertSame([], $this->db->select('SELECT * FROM adopted_provider_identities'));
    }
    public function testInboundForeignKeyStopsBeforeChangingItsTarget(): void
    {
        $before = $this->legacyService();
        $this->db->statement('CREATE TABLE fixture_refs (service_id INT, FOREIGN KEY (service_id) REFERENCES services(id))');
        $this->db->statement('INSERT INTO fixture_refs VALUES (11)');
        $this->expectSafeLegacyStop();
        self::assertSame($before, $this->db->select('SELECT * FROM services'));
        self::assertSame(11, (int) $this->db->selectOne('SELECT service_id FROM fixture_refs')['service_id']);
    }
    public function testMalformedMetadataStopsBeforeArchiving(): void
    {
        $this->legacyService();
        $this->db->statement('UPDATE services SET metadata_json = ? WHERE id = 11', ['{']);
        $before = $this->db->select('SELECT * FROM services');
        $this->expectSafeLegacyStop();
        self::assertSame($before, $this->db->select('SELECT * FROM services'));
    }
    public function testUnknownIdentityStateStopsBeforeLegacyAdoptionChanges(): void
    {
        $before = $this->legacyService();
        $id = \Coleza\Foundation\Database\PdoSchema::autoIncrement($this->db->getPdo());
        $this->db->statement("CREATE TABLE users (id {$id}, email VARCHAR(255), password_hash VARCHAR(255))");
        $this->expectSafeLegacyStop();
        self::assertSame($before, $this->db->select('SELECT * FROM services'));
    }
    public function testMetadataGrowthPastCanonicalTextLimitStopsBeforeDdl(): void
    {
        $this->legacyService();
        $this->db->statement('UPDATE services SET metadata_json = ? WHERE id = 11', [json_encode(['note' => str_repeat('x', 65480)])]);
        $before = $this->db->select('SELECT * FROM services');
        $this->expectSafeLegacyStop();
        self::assertSame($before, $this->db->select('SELECT * FROM services'));
    }
    private function expectSafeLegacyStop(): void
    {
        $tables = $this->tables();
        $caught = null;
        try { (new \Coleza\Domain\Installer\DatabaseSetupService())->initializeApplicationSchema($this->db); }
        catch (\LogicException|\RuntimeException $error) { $caught = $error; }
        self::assertNotNull($caught);
        self::assertSame($tables, $this->tables());
    }
    private function tables(): array
    {
        return $this->db->getDriverName() === 'sqlite'
            ? $this->db->select("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")
            : $this->db->select('SHOW TABLES');
    }
}
