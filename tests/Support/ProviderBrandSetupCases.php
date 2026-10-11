<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;

use Coleza\Domain\Installer\{AdminBootstrapService, AdminSetupDto, BrandSetupService, BrandSetupDto, DatabaseSetupService};

trait ProviderBrandSetupCases
{
    private function prepareProviderFixture(): int
    {
        (new DatabaseSetupService())->initializeCoreSchema($this->db);
        return (new AdminBootstrapService())->bootstrapAdmin($this->db, new AdminSetupDto('provider@example.test', 'ProviderFixture123!', 'Provider', 'Admin'))['id'];
    }
    private function brandDto(): BrandSetupDto
    {
        return new BrandSetupDto('Provider Company', 'Provider Brand', 'support@example.test');
    }
    public function testCustomerOrganizationAndUnrelatedBrandAreNotOverwritten(): void
    {
        $owner = $this->prepareProviderFixture();
        $customer = (int) $this->db->insert('organizations', ['name' => 'Customer Company', 'slug' => 'customer', 'owner_user_id' => 77, 'billing_currency' => 'EUR']);
        $this->db->insert('brands', ['name' => 'Other Brand', 'code' => 'other', 'is_active' => 1]);
        $beforeOrg = $this->db->select('SELECT * FROM organizations');
        $beforeBrand = $this->db->select('SELECT * FROM brands');
        $result = (new BrandSetupService())->configureBrand($this->db, $this->brandDto(), ownerUserId: $owner);
        self::assertNotSame($customer, $result['organization_id']);
        self::assertSame($beforeOrg[0], $this->db->selectOne('SELECT * FROM organizations WHERE id = ?', [$customer]));
        self::assertSame($beforeBrand[0], $this->db->selectOne('SELECT * FROM brands WHERE id = ?', [$beforeBrand[0]['id']]));
        $org = $this->db->selectOne('SELECT * FROM organizations WHERE id = ?', [$result['organization_id']]);
        self::assertSame($owner, (int) $org['owner_user_id']);
        self::assertSame('owner', $this->db->selectOne('SELECT role FROM organization_members WHERE organization_id = ? AND user_id = ?', [$org['id'], $owner])['role']);
        $again = (new BrandSetupService())->configureBrand($this->db, $this->brandDto(), ownerUserId: $owner);
        self::assertSame($result['organization_id'], $again['organization_id']);
        self::assertSame($result['brand_id'], $again['brand_id']);
        self::assertCount(2, $this->db->select('SELECT * FROM organizations'));
    }
    public function testLegacyNotNullOwnerReceivesExplicitOwner(): void
    {
        $id = $this->db->getDriverName() === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $this->db->statement("CREATE TABLE organizations (id {$id}, name VARCHAR(150) NOT NULL, slug VARCHAR(100) UNIQUE, owner_user_id INT NOT NULL)");
        $owner = $this->prepareProviderFixture();
        $result = (new BrandSetupService())->configureBrand($this->db, $this->brandDto(), ownerUserId: $owner);
        self::assertSame($owner, (int) $this->db->selectOne('SELECT owner_user_id FROM organizations WHERE id = ?', [$result['organization_id']])['owner_user_id']);
    }
    public function testMissingOwnerIsRejectedWithoutChangingRecords(): void
    {
        $this->prepareProviderFixture();
        $caught = null;
        try { (new BrandSetupService())->configureBrand($this->db, $this->brandDto()); }
        catch (\Coleza\Foundation\Exceptions\ValidationException $error) { $caught = $error; }
        self::assertNotNull($caught);
        self::assertSame([], $this->db->select('SELECT * FROM organizations'));
        self::assertSame([], $this->db->select('SELECT * FROM brands'));
    }
    public function testOwnerlessCallCannotMutateACustomerOrganization(): void
    {
        $this->prepareProviderFixture();
        $customer = (int) $this->db->insert('organizations', ['name' => 'Protected Customer', 'slug' => 'protected', 'owner_user_id' => 77]);
        $before = $this->db->selectOne('SELECT * FROM organizations WHERE id = ?', [$customer]);
        try { (new BrandSetupService())->configureBrand($this->db, $this->brandDto()); }
        catch (\Coleza\Foundation\Exceptions\ValidationException) { }
        self::assertSame($before, $this->db->selectOne('SELECT * FROM organizations WHERE id = ?', [$customer]));
    }
    public function testOrdinaryUserCannotConfigureProviderIdentity(): void
    {
        $this->prepareProviderFixture();
        $user = (int) $this->db->insert('users', ['email' => 'customer@example.test', 'password_hash' => 'fixture', 'name' => 'Customer']);
        $caught = null;
        try { (new BrandSetupService())->configureBrand($this->db, $this->brandDto(), ownerUserId: $user); }
        catch (\Coleza\Foundation\Exceptions\ValidationException $error) { $caught = $error; }
        self::assertNotNull($caught);
        self::assertSame([], $this->db->select('SELECT * FROM organizations'));
    }
    public function testLegacyProviderNeedsExplicitBindingAndKeepsCustomersIntact(): void
    {
        $owner = $this->prepareProviderFixture();
        $customer = (int) $this->db->insert('organizations', ['name' => 'Customer', 'slug' => 'customer', 'owner_user_id' => 77]);
        $provider = (int) $this->db->insert('organizations', ['name' => 'Legacy Provider', 'slug' => 'legacy-provider']);
        $brand = (int) $this->db->insert('brands', ['name' => 'Legacy Brand', 'code' => 'legacy-brand']);
        $this->db->insert('system_settings', ['setting_key' => 'brand.name', 'setting_value' => 'Legacy Brand']);
        $before = $this->db->select('SELECT * FROM organizations ORDER BY id');
        $service = new BrandSetupService();
        $caught = null;
        try { $service->configureBrand($this->db, $this->brandDto(), ownerUserId: $owner); }
        catch (\Coleza\Foundation\Exceptions\ValidationException $error) { $caught = $error; }
        self::assertNotNull($caught);
        self::assertSame($before, $this->db->select('SELECT * FROM organizations ORDER BY id'));
        $service->bindExistingProvider($this->db, $provider, $brand, $owner);
        $service->bindExistingProvider($this->db, $provider, $brand, $owner);
        $result = $service->configureBrand($this->db, $this->brandDto(), ownerUserId: $owner);
        self::assertSame($provider, $result['organization_id']);
        self::assertSame($brand, $result['brand_id']);
        self::assertSame($before[0], $this->db->selectOne('SELECT * FROM organizations WHERE id = ?', [$customer]));
    }
    public function testBindingAnotherCustomersOrganizationIsRejected(): void
    {
        $owner = $this->prepareProviderFixture();
        $customer = (int) $this->db->insert('organizations', ['name' => 'Customer', 'slug' => 'customer', 'owner_user_id' => 77]);
        $brand = (int) $this->db->insert('brands', ['name' => 'Customer Brand', 'code' => 'customer-brand']);
        $caught = null;
        try { (new BrandSetupService())->bindExistingProvider($this->db, $customer, $brand, $owner); }
        catch (\Coleza\Foundation\Exceptions\ValidationException $error) { $caught = $error; }
        self::assertNotNull($caught);
        self::assertSame(77, (int) $this->db->selectOne('SELECT owner_user_id FROM organizations WHERE id = ?', [$customer])['owner_user_id']);
        self::assertNull($this->db->selectOne('SELECT organization_id FROM installer_provider_identity')['organization_id']);
    }
    public function testSettingsWriteFailureRollsBackOrganizationBrandMembershipAndBinding(): void
    {
        $owner = $this->prepareProviderFixture();
        $trigger = $this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER reject_brand_settings BEFORE INSERT ON system_settings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected brand settings failure'"
            : "CREATE TRIGGER reject_brand_settings BEFORE INSERT ON system_settings BEGIN SELECT RAISE(ABORT, 'Injected brand settings failure'); END";
        $this->db->statement($trigger);
        try {
            (new BrandSetupService())->configureBrand($this->db, $this->brandDto(), ownerUserId: $owner);
            self::fail('Injected settings failure must reach the caller.');
        } catch (\PDOException $error) { self::assertStringContainsString('Injected brand settings failure', $error->getMessage()); }
        foreach (['organizations', 'brands', 'organization_members', 'system_settings'] as $table) { self::assertSame([], $this->db->select("SELECT * FROM {$table}")); }
        self::assertNull($this->db->selectOne('SELECT organization_id FROM installer_provider_identity')['organization_id']);
        self::assertFalse($this->db->inTransaction());
        $this->db->statement('DROP TRIGGER reject_brand_settings');
        self::assertGreaterThan(0, (new BrandSetupService())->configureBrand($this->db, $this->brandDto(), ownerUserId: $owner)['organization_id']);
    }
    public function testMigrationDoesNotResetAnExistingProviderBinding(): void
    {
        $owner = $this->prepareProviderFixture();
        (new BrandSetupService())->configureBrand($this->db, $this->brandDto(), ownerUserId: $owner);
        $before = $this->db->select('SELECT * FROM installer_provider_identity');
        $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_10_11_000008_provider_identity.php';
        $migration->up($this->db);
        $migration->up($this->db);
        self::assertSame($before, $this->db->select('SELECT * FROM installer_provider_identity'));
    }
}
