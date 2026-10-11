<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class ProviderBrandSetupTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\ProviderBrandSetupCases;
    protected function setUp(): void
    {
        parent::setUp();
        $this->root->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
    }
    public function testParallelBrandSetupUsesOneProviderIdentity(): void
    {
        $owner = $this->prepareProviderFixture();
        $results = $this->runParallel('providerBrand', $owner);
        self::assertCount(1, array_unique(array_column($results, 'organization_id')));
        self::assertCount(1, array_unique(array_column($results, 'brand_id')));
        self::assertCount(1, $this->db->select('SELECT * FROM organizations'));
        self::assertCount(1, $this->db->select('SELECT * FROM brands'));
        self::assertCount(1, $this->db->select('SELECT * FROM organization_members'));
    }
}
