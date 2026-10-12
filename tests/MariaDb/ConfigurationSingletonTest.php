<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class ConfigurationSingletonTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\ConfigurationSingletonCases;
    protected function setUp(): void { parent::setUp(); $this->root->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false); }
    public function testParallelBrandCreatesAllowExactlyOneActiveBrand(): void
    {
        $this->singletonSchema();
        $results = $this->runParallel('configurationBrandCreate', 0, getenv('COLEZA_CONFIGURATION_BASELINE') === '1' ? 'baseline' : 'current');
        self::assertCount(1, $this->db->select('SELECT * FROM brands WHERE is_active = 1'));
        self::assertSame(1, count(array_filter($results, fn($result) => $result['status'] === 'ok')));
        self::assertSame(3, count(array_filter($results, fn($result) => $result['status'] === 'rejected')));
    }
    public function testParallelDefaultChangesKeepEveryCurrencyAndOneDefault(): void
    {
        $this->singletonSchema();
        $results = $this->runParallel('configurationCurrencyDefault', 0, getenv('COLEZA_CONFIGURATION_BASELINE') === '1' ? 'baseline' : 'current');
        self::assertCount(1, $this->db->select('SELECT * FROM currencies WHERE is_default = 1'));
        self::assertCount(4, $this->db->select('SELECT * FROM currencies'));
        foreach ($results as $result) { self::assertSame('ok', $result['status']); }
    }
    public function testParallelFallbacksReturnSameBrandAndCurrency(): void
    {
        $this->singletonSchema(); $results = $this->runParallel('configurationFallback', 0);
        self::assertCount(1, array_unique(array_column($results, 'brand_id')));
        self::assertSame(['TRY'], array_values(array_unique(array_column($results, 'currency'))));
        self::assertCount(1, $this->db->select('SELECT * FROM brands')); self::assertCount(1, $this->db->select('SELECT * FROM currencies'));
    }
    public function testInstallerAndDomainWorkersShareBrandSerialization(): void
    {
        (new \Coleza\Domain\Installer\DatabaseSetupService())->initializeCoreSchema($this->db);
        $owner = (new \Coleza\Domain\Installer\AdminBootstrapService())->bootstrapAdmin($this->db, new \Coleza\Domain\Installer\AdminSetupDto('owner@example.test', 'OwnerFixture123!', 'Owner', 'Admin'))['id'];
        $results = $this->runParallel('configurationMixedBrand', $owner);
        self::assertCount(1, $this->db->select('SELECT * FROM brands WHERE is_active = 1'));
        $orgs = $this->db->select('SELECT * FROM organizations'); self::assertLessThanOrEqual(1, count($orgs));
        $binding = $this->db->selectOne('SELECT * FROM installer_provider_identity WHERE id = 1');
        self::assertSame($orgs === [], $binding['organization_id'] === null);
        if ($orgs !== []) { self::assertSame((int) $orgs[0]['id'], (int) $binding['organization_id']); }
        self::assertSame(count($orgs), count($this->db->select('SELECT * FROM organization_members')));
        foreach ($results as $result) { self::assertContains($result['status'], ['ok', 'rejected']); }
    }
    public function testOldRawCallerSnapshotSeesCommittedBrandAndDefault(): void
    {
        $this->singletonSchema(); $this->db->getPdo()->beginTransaction();
        try {
            self::assertSame([], $this->db->select('SELECT * FROM brands')); self::assertSame([], $this->db->select('SELECT * FROM currencies'));
            $other = new \PDO((string) getenv('COLEZA_TEST_MARIADB_DSN'), getenv('COLEZA_TEST_MARIADB_USER'), getenv('COLEZA_TEST_MARIADB_PASSWORD'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false]);
            $other->exec('USE `' . $this->database . '`'); $db = new \Coleza\Foundation\Database\Connection($other);
            $id = (new \Coleza\Domain\Brand\BrandService($db))->createBrand('Committed', 'committed');
            (new \Coleza\Domain\Finance\Currency\CurrencyService($db))->createCurrency($this->currencyFixture('EUR', true));
            self::assertSame($id, (int) (new \Coleza\Domain\Brand\BrandService($this->db))->getActiveBrand()['id']);
            self::assertSame('EUR', (new \Coleza\Domain\Finance\Currency\CurrencyService($this->db))->getDefaultCurrency()->getCode());
            self::assertTrue($this->db->getPdo()->inTransaction());
        } finally { $this->db->getPdo()->rollBack(); }
        self::assertCount(1, $this->db->select('SELECT * FROM brands')); self::assertCount(1, $this->db->select('SELECT * FROM currencies'));
    }
    public function testNonTransactionalCurrencyStorageRefusesDefaultChangeWithoutDataLoss(): void
    {
        $this->singletonSchema(); $currency = new \Coleza\Domain\Finance\Currency\CurrencyService($this->db);
        $currency->createCurrency($this->currencyFixture('USD', true)); $before = $this->db->select('SELECT * FROM currencies');
        $this->db->statement('ALTER TABLE currencies ENGINE=MyISAM');
        try {
            try { $currency->createCurrency($this->currencyFixture('EUR', true)); self::fail('Nontransactional storage accepted'); }
            catch (\LogicException $error) { self::assertStringContainsString('require InnoDB', $error->getMessage()); }
            self::assertSame($before, $this->db->select('SELECT * FROM currencies'));
        } finally { $this->db->statement('ALTER TABLE currencies ENGINE=InnoDB'); }
    }
}
