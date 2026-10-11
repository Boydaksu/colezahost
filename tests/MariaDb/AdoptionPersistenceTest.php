<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class AdoptionPersistenceTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\AdoptionPersistenceCases;
    protected function setUp(): void
    {
        parent::setUp();
        $this->root->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
    }
    public function testFourConcurrentServiceAdoptionsCreateOneServiceAndIdentity(): void
    {
        (new \Coleza\Domain\Installer\DatabaseSetupService())->initializeApplicationSchema($this->db);
        $results = $this->runParallel('adoptService', 0);
        self::assertCount(1, array_unique(array_column($results, 'entity_id')));
        self::assertCount(1, $this->db->select('SELECT * FROM services'));
        self::assertCount(1, $this->db->select('SELECT * FROM service_placements'));
        self::assertCount(1, $this->db->select('SELECT * FROM adopted_provider_identities'));
    }
    public function testFourConcurrentDomainAdoptionsCreateOneDomainAndIdentity(): void
    {
        (new \Coleza\Domain\Installer\DatabaseSetupService())->initializeApplicationSchema($this->db);
        $results = $this->runParallel('adoptDomain', 0);
        self::assertCount(1, array_unique(array_column($results, 'entity_id')));
        self::assertCount(1, $this->db->select('SELECT * FROM domains'));
        self::assertCount(1, $this->db->select('SELECT * FROM domain_timeline_events'));
        self::assertCount(1, $this->db->select('SELECT * FROM adopted_provider_identities'));
    }
}
