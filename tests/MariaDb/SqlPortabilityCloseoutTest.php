<?php

declare(strict_types=1);

namespace Coleza\Tests\MariaDb;

use Coleza\Domain\Domains\Catalog\DomainCatalogService;
use Coleza\Domain\Domains\DomainService;
use Coleza\Domain\Installer\DatabaseSetupService;
use Coleza\Tests\Support\MariaDbTestCase;

/** Execute the two original F02 domain upserts using production schema methods and migrations. */
final class SqlPortabilityCloseoutTest extends MariaDbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->root->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
        (new DatabaseSetupService())->initializeApplicationSchema($this->db);
        // Catalog has an explicit module setup method; it is not in the 26 baseline migrations.
        (new DomainCatalogService($this->db))->ensureTables();
    }

    public function testDomainPricingUpdatesOnlyMatchingOperationYearAndCurrency(): void
    {
        $catalog = new DomainCatalogService($this->db);
        $tld = $catalog->registerTld(['extension' => '.com', 'registrar_id' => 'fixture']);
        $original = $catalog->setTldPricing($tld->getId(), 'register', 1, 1000, 'TRY', 500);
        $catalog->setTldPricing($tld->getId(), 'renew', 1, 2000, 'TRY', 700);
        $catalog->setTldPricing($tld->getId(), 'register', 1, 3000, 'USD', 900);
        $updated = $catalog->setTldPricing($tld->getId(), 'register', 1, 1500, 'TRY', 600);
        self::assertSame($original->getId(), $updated->getId());
        self::assertSame(1500, $updated->getPriceMinor());
        self::assertSame(600, $updated->getCostMinor());
        self::assertSame(2000, $catalog->getTldPricing($tld->getId(), 'renew', 1, 'TRY')->getPriceMinor());
        self::assertSame(3000, $catalog->getTldPricing($tld->getId(), 'register', 1, 'USD')->getPriceMinor());
        self::assertCount(3, $catalog->listTldPricing($tld->getId()));
        $this->root->beginTransaction();
        try { $catalog->setTldPricing($tld->getId(), 'register', 1, 9999, 'TRY'); }
        finally { $this->root->rollBack(); }
        self::assertSame(1500, $catalog->getTldPricing($tld->getId(), 'register', 1, 'TRY')->getPriceMinor());
    }

    public function testDomainContactsRepeatWithoutReplacingOtherContacts(): void
    {
        $domains = new DomainService($this->db);
        $domain = $domains->createDomain(['user_id' => 7, 'domain' => 'closeout.example']);
        $first = $domains->setContact($domain->getId(), 'registrant', ['first_name' => 'İlk', 'last_name' => 'Kişi', 'email' => 'first@example.test', 'phone' => '+905551112233']);
        $domains->setContact($domain->getId(), 'admin', ['first_name' => 'Admin', 'last_name' => 'User', 'email' => 'admin@example.test', 'phone' => '+905551112233']);
        $second = $domains->setContact($domain->getId(), 'registrant', ['first_name' => 'Çağrı', 'last_name' => 'Kişi', 'email' => 'second@example.test', 'phone' => '+905551112233']);
        self::assertSame($first->getId(), $second->getId());
        self::assertSame('Çağrı', $second->getFirstName());
        self::assertSame('second@example.test', $domains->getContact($domain->getId(), 'registrant')->getEmail());
        self::assertSame('admin@example.test', $domains->getContact($domain->getId(), 'admin')->getEmail());
        self::assertCount(2, $domains->getAllContacts($domain->getId()));
    }
}
