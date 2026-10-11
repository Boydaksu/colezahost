<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;

trait AdoptionPersistenceCases
{
    private function adopters(): array
    {
        $repo = new \Coleza\Domain\Migration\Adoption\AdoptedIdentityRepository($this->db);
        $resolver = new \Coleza\Domain\Migration\Adoption\ProviderIdentityResolver(
            serverMap: ['fixture' => 3], productMap: ['product' => 5], clientMap: ['client' => 7]
        );
        return [new \Coleza\Domain\Migration\Adoption\ServiceAdoptionService($this->db, $repo, $resolver),
            new \Coleza\Domain\Migration\Adoption\DomainAdoptionService($this->db, $repo, $resolver)];
    }
    private function serviceDto(): \Coleza\Domain\Migration\Canonical\CanonicalServiceDto
    {
        return new \Coleza\Domain\Migration\Canonical\CanonicalServiceDto('service-1', 'whmcs', 'client', 'product', 'example.test', 'fixture-user', recurringAmount: 19.99, registrationDate: '2025-01-02', nextDueDate: '2026-12-02');
    }
    private function domainDto(): \Coleza\Domain\Migration\Canonical\CanonicalDomainDto
    {
        return new \Coleza\Domain\Migration\Canonical\CanonicalDomainDto('domain-1', 'whmcs', 'client', 'Example.test', 'manual', recurringAmount: 9.99, registrationDate: '2025-01-02', expiryDate: '2026-12-02', nextDueDate: '2026-11-02', nameServers: ['ns1.example.test']);
    }
    public function testInstalledServiceAdoptionIsVisibleToApplicationAndRepeatSafe(): void
    {
        $setup = new \Coleza\Domain\Installer\DatabaseSetupService();
        $setup->initializeApplicationSchema($this->db);
        [$services] = $this->adopters();
        $result = $services->adoptService($this->serviceDto(), 'fixture-batch', 'fixture');
        self::assertTrue($result->isSuccess());
        $loaded = (new \Coleza\Domain\Commerce\Services\ServiceService($this->db))->findServiceById($result->getAdoptedServiceId());
        self::assertSame(1999, $loaded->getRecurringAmountMinor());
        self::assertSame('USD', $loaded->getCurrencyCode());
        self::assertSame('2026-12-02', $loaded->getNextDueDate());
        self::assertTrue($loaded->getMetadata()['provisioning_suppressed']);
        $before = $this->db->select('SELECT * FROM services');
        $setup->initializeApplicationSchema($this->db);
        self::assertSame($before, $this->db->select('SELECT * FROM services'));
        self::assertSame($result->getAdoptedServiceId(), $services->adoptService($this->serviceDto(), 'fixture-batch', 'fixture')->getAdoptedServiceId());
        self::assertCount(1, $this->db->select('SELECT * FROM services'));
    }
    public function testInstalledDomainAdoptionIsVisibleToApplicationAndRepeatSafe(): void
    {
        $setup = new \Coleza\Domain\Installer\DatabaseSetupService();
        $setup->initializeApplicationSchema($this->db);
        [, $domains] = $this->adopters();
        $result = $domains->adoptDomain($this->domainDto(), 'fixture-batch');
        self::assertTrue($result->isSuccess());
        $loaded = (new \Coleza\Domain\Domains\DomainService($this->db))->findDomainByName('example.test');
        self::assertSame($result->getAdoptedDomainId(), $loaded->getId());
        self::assertSame('2026-12-02', $loaded->getExpiryDate());
        self::assertSame(['ns1.example.test'], $loaded->getNameservers());
        self::assertTrue($loaded->getMetadata()['registrar_api_suppressed']);
        self::assertSame(9.99, $loaded->getMetadata()['adopted_billing']['recurring_amount']);
        self::assertSame('USD', $loaded->getMetadata()['adopted_billing']['currency_code']);
        $before = $this->db->select('SELECT * FROM domains');
        $setup->initializeApplicationSchema($this->db);
        self::assertSame($before, $this->db->select('SELECT * FROM domains'));
        self::assertSame($result->getAdoptedDomainId(), $domains->adoptDomain($this->domainDto(), 'fixture-batch')->getAdoptedDomainId());
        self::assertCount(1, $this->db->select('SELECT * FROM domains'));
    }
    public function testIdentityWriteFailureLeavesNoOrphanService(): void
    {
        [$services] = $this->adopters();
        $services->ensureServicesTable();
        (new \Coleza\Domain\Migration\Adoption\AdoptedIdentityRepository($this->db))->ensureTable();
        $this->rejectIdentityWrite();
        $caught = null;
        try { $services->adoptService($this->serviceDto(), 'fixture-batch', 'fixture'); }
        catch (\PDOException $error) { $caught = $error; }
        self::assertNotNull($caught);
        self::assertSame([], $this->db->select('SELECT * FROM services'));
        self::assertSame([], $this->db->select('SELECT * FROM adopted_provider_identities'));
        self::assertSame([], $this->db->select('SELECT * FROM service_placements'));
    }
    public function testIdentityWriteFailureLeavesNoOrphanDomain(): void
    {
        [, $domains] = $this->adopters();
        $domains->ensureDomainsTable();
        (new \Coleza\Domain\Migration\Adoption\AdoptedIdentityRepository($this->db))->ensureTable();
        $this->rejectIdentityWrite();
        $caught = null;
        try { $domains->adoptDomain($this->domainDto(), 'fixture-batch'); }
        catch (\PDOException $error) { $caught = $error; }
        self::assertNotNull($caught);
        self::assertSame([], $this->db->select('SELECT * FROM domains'));
        self::assertSame([], $this->db->select('SELECT * FROM adopted_provider_identities'));
        self::assertSame([], $this->db->select('SELECT * FROM domain_timeline_events'));
    }
    public function testMissingServiceDatesDoNotCreateBillingDatesOrService(): void
    {
        [$services] = $this->adopters();
        $result = $services->adoptService(new \Coleza\Domain\Migration\Canonical\CanonicalServiceDto('unknown-dates', 'whmcs', 'client', 'product'), 'fixture-batch', 'fixture');
        self::assertFalse($result->isSuccess());
        self::assertSame([], $this->db->select('SELECT * FROM services'));
        self::assertSame([], $this->db->select('SELECT * FROM adopted_provider_identities'));
    }
    public function testServiceReplayCannotReuseAnotherCustomerOrSourceSystem(): void
    {
        [$services] = $this->adopters();
        $original = $services->adoptService($this->serviceDto(), 'fixture-batch', 'fixture');
        self::assertTrue($original->isSuccess());
        $before = $this->db->select('SELECT * FROM services');
        $other = new \Coleza\Domain\Migration\Adoption\ServiceAdoptionService($this->db,
            new \Coleza\Domain\Migration\Adoption\AdoptedIdentityRepository($this->db),
            new \Coleza\Domain\Migration\Adoption\ProviderIdentityResolver(serverMap: ['fixture' => 3], productMap: ['product' => 5], clientMap: ['client' => 8]));
        self::assertFalse($other->adoptService($this->serviceDto(), 'other-batch', 'fixture')->isSuccess());
        $otherSystem = new \Coleza\Domain\Migration\Canonical\CanonicalServiceDto('service-1', 'other-system', 'client', 'product', 'example.test', 'fixture-user', registrationDate: '2025-01-02', nextDueDate: '2026-12-02');
        self::assertFalse($services->adoptService($otherSystem, 'other-batch', 'fixture')->isSuccess());
        self::assertSame($before, $this->db->select('SELECT * FROM services'));
    }
    public function testDomainReplayCannotReuseAnotherCustomer(): void
    {
        [, $domains] = $this->adopters();
        self::assertTrue($domains->adoptDomain($this->domainDto(), 'fixture-batch')->isSuccess());
        $before = $this->db->select('SELECT * FROM domains');
        $other = new \Coleza\Domain\Migration\Adoption\DomainAdoptionService($this->db,
            new \Coleza\Domain\Migration\Adoption\AdoptedIdentityRepository($this->db),
            new \Coleza\Domain\Migration\Adoption\ProviderIdentityResolver(clientMap: ['client' => 8]));
        self::assertFalse($other->adoptDomain($this->domainDto(), 'other-batch')->isSuccess());
        self::assertSame($before, $this->db->select('SELECT * FROM domains'));
    }
    public function testLegacyServiceTableStopsWithoutChangingRecords(): void
    {
        $id = \Coleza\Foundation\Database\PdoSchema::autoIncrement($this->db->getPdo());
        $this->db->statement("CREATE TABLE services (id {$id}, user_id INT, recurring_amount DECIMAL(10,2), currency VARCHAR(3))");
        $this->db->statement("INSERT INTO services (user_id, recurring_amount, currency) VALUES (7, 19.99, 'USD')");
        $before = $this->db->select('SELECT * FROM services');
        [$services] = $this->adopters();
        $caught = null;
        try { $services->ensureServicesTable(); } catch (\LogicException $error) { $caught = $error; }
        self::assertNotNull($caught);
        self::assertSame($before, $this->db->select('SELECT * FROM services'));
    }
    public function testUnknownProductOrServerMappingDoesNotDefaultToFirstRecord(): void
    {
        [$services] = $this->adopters();
        self::assertFalse($services->adoptService($this->serviceDto(), 'fixture-batch', 'unmapped')->isSuccess());
        $dto = new \Coleza\Domain\Migration\Canonical\CanonicalServiceDto('unknown-product', 'whmcs', 'client', 'unmapped', registrationDate: '2025-01-01', nextDueDate: '2026-12-01');
        self::assertFalse($services->adoptService($dto, 'fixture-batch', 'fixture')->isSuccess());
        self::assertSame([], $this->db->select('SELECT * FROM services'));
        self::assertSame([], $this->db->select('SELECT * FROM adopted_provider_identities'));
    }
    public function testLegacyDomainTableStopsWithoutChangingRecords(): void
    {
        $id = \Coleza\Foundation\Database\PdoSchema::autoIncrement($this->db->getPdo());
        $this->db->statement("CREATE TABLE domains (id {$id}, user_id INT, domain_name VARCHAR(255))");
        $this->db->statement("INSERT INTO domains (user_id, domain_name) VALUES (7, 'legacy.test')");
        $before = $this->db->select('SELECT * FROM domains');
        [, $domains] = $this->adopters();
        $caught = null;
        try { $domains->ensureDomainsTable(); } catch (\LogicException $error) { $caught = $error; }
        self::assertNotNull($caught);
        self::assertSame($before, $this->db->select('SELECT * FROM domains'));
    }
    private function rejectIdentityWrite(): void
    {
        $sql = $this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER reject_identity BEFORE INSERT ON adopted_provider_identities FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture identity failure'"
            : "CREATE TRIGGER reject_identity BEFORE INSERT ON adopted_provider_identities BEGIN SELECT RAISE(ABORT, 'fixture identity failure'); END";
        $this->db->statement($sql);
    }
}
