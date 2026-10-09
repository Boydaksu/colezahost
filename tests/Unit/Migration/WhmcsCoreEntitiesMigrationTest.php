<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Migration;

use Coleza\Domain\Migration\Adoption\AdoptedIdentityRepository;
use Coleza\Domain\Migration\Adoption\AdoptedIdentityType;
use Coleza\Domain\Migration\Adoption\DomainAdoptionService;
use Coleza\Domain\Migration\Adoption\ProviderIdentityResolver;
use Coleza\Domain\Migration\Adoption\ServiceAdoptionService;
use Coleza\Domain\Migration\Mapping\GenericMappingEngine;
use Coleza\Domain\Migration\Staging\DatabaseStagingRepository;
use Coleza\Domain\Migration\Staging\StagingPipelineService;
use Coleza\Domain\Migration\Staging\StagingRecordStatus;
use Coleza\Domain\Migration\Validation\CanonicalValidationEngine;
use Coleza\Domain\Migration\Whmcs\Core\WhmcsCoreEntityExtractor;
use Coleza\Domain\Migration\Whmcs\Core\WhmcsCoreEntityMigrator;
use Coleza\Domain\Migration\Whmcs\WhmcsReadOnlyConnector;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class WhmcsCoreEntitiesMigrationTest extends TestCase
{
    private Connection $whmcsDb;
    private Connection $targetDb;
    private WhmcsReadOnlyConnector $whmcsConnector;
    private DatabaseStagingRepository $stagingRepo;
    private StagingPipelineService $stagingPipeline;
    private GenericMappingEngine $mappingEngine;
    private CanonicalValidationEngine $validationEngine;
    private ProviderIdentityResolver $identityResolver;
    private AdoptedIdentityRepository $adoptedRepo;
    private ServiceAdoptionService $serviceAdopter;
    private DomainAdoptionService $domainAdopter;
    private WhmcsCoreEntityExtractor $extractor;
    private WhmcsCoreEntityMigrator $migrator;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. In-memory source WHMCS database
        $whmcsPdo = new PDO('sqlite::memory:');
        $this->whmcsDb = new Connection($whmcsPdo, 'sqlite');
        $this->whmcsConnector = new WhmcsReadOnlyConnector($this->whmcsDb);

        // 2. In-memory target Coleza database
        $targetPdo = new PDO('sqlite::memory:');
        $this->targetDb = new Connection($targetPdo, 'sqlite');

        // 3. Staging and validation infrastructure
        $this->stagingRepo = new DatabaseStagingRepository($this->targetDb);
        $this->mappingEngine = new GenericMappingEngine();
        $this->validationEngine = new CanonicalValidationEngine();
        $this->stagingPipeline = new StagingPipelineService(
            $this->stagingRepo,
            $this->mappingEngine,
            $this->validationEngine
        );

        // 4. Adoption and identity infrastructure
        $this->identityResolver = new ProviderIdentityResolver();
        $this->adoptedRepo = new AdoptedIdentityRepository($this->targetDb);
        $this->serviceAdopter = new ServiceAdoptionService($this->targetDb, $this->adoptedRepo, $this->identityResolver);
        $this->domainAdopter = new DomainAdoptionService($this->targetDb, $this->adoptedRepo, $this->identityResolver);

        // 5. Extractor & Migrator
        $this->extractor = new WhmcsCoreEntityExtractor($this->whmcsConnector);
        $this->migrator = new WhmcsCoreEntityMigrator(
            targetDb: $this->targetDb,
            whmcs: $this->whmcsConnector,
            stagingRepo: $this->stagingRepo,
            stagingPipeline: $this->stagingPipeline,
            mappingEngine: $this->mappingEngine,
            identityResolver: $this->identityResolver,
            serviceAdoptionService: $this->serviceAdopter,
            domainAdoptionService: $this->domainAdopter,
            adoptedIdentityRepo: $this->adoptedRepo,
            extractor: $this->extractor
        );

        // 6. Ensure target schema
        $this->migrator->ensureTargetTables();

        // 7. Seed standard source WHMCS tables
        $this->createSourceWhmcsTables();
    }

    private function createSourceWhmcsTables(): void
    {
        $this->whmcsDb->statement('CREATE TABLE tblcurrencies (
            id INTEGER PRIMARY KEY,
            code VARCHAR(10),
            prefix VARCHAR(10),
            suffix VARCHAR(10),
            rate DECIMAL(10,4),
            `default` INT
        )');

        $this->whmcsDb->statement('CREATE TABLE tblclients (
            id INTEGER PRIMARY KEY,
            firstname VARCHAR(50),
            lastname VARCHAR(50),
            companyname VARCHAR(100),
            email VARCHAR(191),
            address1 VARCHAR(100),
            address2 VARCHAR(100),
            city VARCHAR(50),
            state VARCHAR(50),
            postcode VARCHAR(20),
            country VARCHAR(10),
            phonenumber VARCHAR(30),
            password VARCHAR(255),
            currency INT,
            status VARCHAR(20),
            taxexempt INT,
            datecreated DATETIME,
            notes TEXT
        )');

        $this->whmcsDb->statement('CREATE TABLE tblcontacts (
            id INTEGER PRIMARY KEY,
            userid INT,
            firstname VARCHAR(50),
            lastname VARCHAR(50),
            companyname VARCHAR(100),
            email VARCHAR(191),
            address1 VARCHAR(100),
            address2 VARCHAR(100),
            city VARCHAR(50),
            state VARCHAR(50),
            postcode VARCHAR(20),
            country VARCHAR(10),
            phonenumber VARCHAR(30),
            subaccount INT,
            password VARCHAR(255),
            permissions VARCHAR(255)
        )');

        $this->whmcsDb->statement('CREATE TABLE tblproductgroups (
            id INTEGER PRIMARY KEY,
            name VARCHAR(100),
            slug VARCHAR(100),
            headline VARCHAR(255),
            `order` INT,
            disabled INT
        )');

        $this->whmcsDb->statement('CREATE TABLE tblproducts (
            id INTEGER PRIMARY KEY,
            gid INT,
            type VARCHAR(50),
            name VARCHAR(100),
            description TEXT,
            paytype VARCHAR(20),
            servertype VARCHAR(50),
            configoption1 VARCHAR(100),
            retired INT,
            showdomainoptions INT
        )');

        $this->whmcsDb->statement('CREATE TABLE tblpricing (
            id INTEGER PRIMARY KEY,
            type VARCHAR(20),
            currency INT,
            relid INT,
            monthly DECIMAL(10,2),
            quarterly DECIMAL(10,2),
            semiannually DECIMAL(10,2),
            annually DECIMAL(10,2)
        )');

        $this->whmcsDb->statement('CREATE TABLE tblhosting (
            id INTEGER PRIMARY KEY,
            userid INT,
            orderid INT,
            packageid INT,
            server INT,
            regdate DATE,
            domain VARCHAR(255),
            paymentmethod VARCHAR(50),
            firstpaymentamount DECIMAL(10,2),
            amount DECIMAL(10,2),
            billingcycle VARCHAR(30),
            nextduedate DATE,
            domainstatus VARCHAR(30),
            username VARCHAR(50),
            password VARCHAR(255),
            notes TEXT,
            subscriptionid VARCHAR(100),
            suspendreason VARCHAR(255)
        )');

        $this->whmcsDb->statement('CREATE TABLE tbldomains (
            id INTEGER PRIMARY KEY,
            userid INT,
            orderid INT,
            registrationdate DATE,
            domain VARCHAR(255),
            firstpaymentamount DECIMAL(10,2),
            recurringamount DECIMAL(10,2),
            registrar VARCHAR(50),
            registrationperiod INT,
            expirydate DATE,
            nextduedate DATE,
            status VARCHAR(30),
            paymentmethod VARCHAR(50),
            dnsmanagement INT,
            emailforwarding INT,
            idprotection INT,
            donotrenew INT,
            subscriptionid VARCHAR(100)
        )');

        $this->whmcsDb->statement('CREATE TABLE tblcustomfields (
            id INTEGER PRIMARY KEY,
            type VARCHAR(32),
            relid INT,
            fieldname VARCHAR(100)
        )');

        $this->whmcsDb->statement('CREATE TABLE tblcustomfieldsvalues (
            id INTEGER PRIMARY KEY,
            fieldid INT,
            relid INT,
            value TEXT
        )');
    }

    public function testExtractorCurrenciesAndCustomFields(): void
    {
        $this->whmcsDb->statement("INSERT INTO tblcurrencies VALUES (1, 'USD', '$', '', 1.0, 1)");
        $this->whmcsDb->statement("INSERT INTO tblcurrencies VALUES (2, 'EUR', '€', '', 0.9, 0)");
        $this->whmcsDb->statement("INSERT INTO tblcurrencies VALUES (3, 'TRY', '₺', '', 34.0, 0)");

        $currencies = $this->extractor->getCurrencies();
        $this->assertSame('USD', $currencies[1]);
        $this->assertSame('EUR', $currencies[2]);
        $this->assertSame('TRY', $currencies[3]);

        $this->assertSame('USD', $this->extractor->resolveCurrencyCode(1));
        $this->assertSame('EUR', $this->extractor->resolveCurrencyCode('2'));
        $this->assertSame('GBP', $this->extractor->resolveCurrencyCode('GBP'));
        $this->assertSame('USD', $this->extractor->resolveCurrencyCode(''));

        // Custom fields
        $this->whmcsDb->statement("INSERT INTO tblcustomfields VALUES (10, 'client', 0, 'Tax Identification No')");
        $this->whmcsDb->statement("INSERT INTO tblcustomfieldsvalues VALUES (100, 10, 5, 'TR1234567890')");

        $fields = $this->extractor->extractCustomFields('client', 5);
        $this->assertArrayHasKey('Tax Identification No', $fields);
        $this->assertSame('TR1234567890', $fields['Tax Identification No']);
    }

    public function testExtractorExtractClientsProductsServicesDomains(): void
    {
        $this->whmcsDb->statement("INSERT INTO tblcurrencies VALUES (1, 'USD', '$', '', 1.0, 1)");

        // Client
        $this->whmcsDb->statement("INSERT INTO tblclients (id, firstname, lastname, companyname, email, currency, status, datecreated)
            VALUES (1, 'John', 'Doe', 'Acme Corp', 'john@acme.com', 1, 'Active', '2025-01-01 10:00:00')");

        // Product group & product with pricing
        $this->whmcsDb->statement("INSERT INTO tblproductgroups (id, name, slug, `order`, disabled) VALUES (1, 'Cloud Hosting', 'cloud-hosting', 1, 0)");
        $this->whmcsDb->statement("INSERT INTO tblproducts (id, gid, type, name, description, servertype, configoption1)
            VALUES (10, 1, 'hostingaccount', 'Business Cloud', 'Fast cloud hosting', 'cpanel', 'cpanel-biz')");
        $this->whmcsDb->statement("INSERT INTO tblpricing (id, type, currency, relid, monthly, annually) VALUES (1, 'product', 1, 10, 15.00, 150.00)");

        // Service
        $this->whmcsDb->statement("INSERT INTO tblhosting (id, userid, packageid, server, regdate, domain, amount, billingcycle, domainstatus, username)
            VALUES (100, 1, 10, 1, '2025-01-05', 'acme.com', 15.00, 'Monthly', 'Active', 'acmeusr')");

        // Domain
        $this->whmcsDb->statement("INSERT INTO tbldomains (id, userid, domain, recurringamount, registrar, registrationperiod, status)
            VALUES (200, 1, 'acme.com', 12.00, 'namecheap', 1, 'Active')");

        $clients = $this->extractor->extractClients();
        $this->assertCount(1, $clients);
        $this->assertSame('john@acme.com', $clients[0]['email']);
        $this->assertSame('USD', $clients[0]['currency']);

        $groups = $this->extractor->extractProductGroups();
        $this->assertCount(1, $groups);
        $this->assertSame('Cloud Hosting', $groups[0]['name']);

        $products = $this->extractor->extractProducts();
        $this->assertCount(1, $products);
        $this->assertSame('Business Cloud', $products[0]['name']);
        $this->assertSame(15.00, $products[0]['price']);
        $this->assertSame('USD', $products[0]['currency']);

        $services = $this->extractor->extractServices();
        $this->assertCount(1, $services);
        $this->assertSame('acme.com', $services[0]['domain']);
        $this->assertSame('USD', $services[0]['currency']);

        $domains = $this->extractor->extractDomains();
        $this->assertCount(1, $domains);
        $this->assertSame('acme.com', $domains[0]['domain']);
        $this->assertSame('namecheap', $domains[0]['registrar']);
    }

    public function testClientMigrationCreatesUserAndOrganization(): void
    {
        $this->whmcsDb->statement("INSERT INTO tblcurrencies VALUES (1, 'USD', '$', '', 1.0, 1)");
        $this->whmcsDb->statement("INSERT INTO tblclients (id, firstname, lastname, companyname, email, currency, status, datecreated)
            VALUES (1, 'Alice', 'Smith', 'Smith Logistics', 'alice@smithlogistics.com', 1, 'Active', '2025-01-01 12:00:00')");

        $batchId = 'batch-client-01';
        $stats = $this->migrator->migrateClients($batchId);

        $this->assertSame(1, $stats['total']);
        $this->assertSame(1, $stats['migrated']);
        $this->assertSame(0, $stats['quarantined']);

        // Check target database
        $user = $this->targetDb->selectOne("SELECT * FROM users WHERE email = 'alice@smithlogistics.com'");
        $this->assertNotNull($user);
        $this->assertSame('Alice Smith', $user['name']);
        $this->assertSame(1, (int)$user['is_active']);

        $org = $this->targetDb->selectOne("SELECT * FROM organizations WHERE name = 'Smith Logistics'");
        $this->assertNotNull($org);
        $this->assertSame((int)$user['id'], (int)$org['owner_user_id']);

        $member = $this->targetDb->selectOne("SELECT * FROM organization_members WHERE organization_id = ? AND user_id = ?", [(int)$org['id'], (int)$user['id']]);
        $this->assertNotNull($member);
        $this->assertSame('owner', $member['role']);

        // Check resolver
        $this->assertSame((int)$user['id'], $this->identityResolver->resolveClient('1'));

        // Check staging record
        $records = $this->stagingRepo->getBatchRecords($batchId);
        $this->assertCount(1, $records);
        $this->assertSame(StagingRecordStatus::MIGRATED, $records[0]->getStatus());
        $this->assertSame((int)$user['id'], $records[0]->getTargetEntityId());
    }

    public function testProductMigrationCreatesProductGroupsAndCatalogProducts(): void
    {
        $this->whmcsDb->statement("INSERT INTO tblcurrencies VALUES (1, 'USD', '$', '', 1.0, 1)");
        $this->whmcsDb->statement("INSERT INTO tblproductgroups (id, name, slug, `order`, disabled) VALUES (5, 'Virtual Private Servers', 'vps', 2, 0)");
        $this->whmcsDb->statement("INSERT INTO tblproducts (id, gid, type, name, description, servertype, configoption1)
            VALUES (50, 5, 'server', 'VPS Starter', 'Entry level VPS', 'proxmox', 'vps-starter-plan')");
        $this->whmcsDb->statement("INSERT INTO tblpricing (id, type, currency, relid, monthly) VALUES (10, 'product', 1, 50, 29.99)");

        $batchId = 'batch-prod-01';
        $stats = $this->migrator->migrateProducts($batchId);

        $this->assertSame(1, $stats['total']);
        $this->assertSame(1, $stats['migrated']);
        $this->assertSame(0, $stats['quarantined']);

        // Check target database
        $group = $this->targetDb->selectOne("SELECT * FROM product_groups WHERE name = 'Virtual Private Servers'");
        $this->assertNotNull($group);

        $product = $this->targetDb->selectOne("SELECT * FROM products WHERE name = 'VPS Starter'");
        $this->assertNotNull($product);
        $this->assertSame((int)$group['id'], (int)$product['group_id']);
        $this->assertSame('server', $product['type']);

        $meta = json_decode((string)$product['metadata_json'], true);
        $this->assertSame(29.99, $meta['price']);
        $this->assertSame('proxmox', $meta['module']);
        $this->assertSame('vps-starter-plan', $meta['package_name']);

        // Resolver
        $this->assertSame((int)$product['id'], $this->identityResolver->resolveProduct('50'));

        // Staging
        $records = $this->stagingRepo->getBatchRecords($batchId);
        $this->assertCount(1, $records);
        $this->assertSame(StagingRecordStatus::MIGRATED, $records[0]->getStatus());
    }

    public function testServiceMigrationAdoptsHostingAccountsNonDestructively(): void
    {
        // Prepopulate resolver with client & product
        $this->identityResolver->registerClientMapping('10', 501);
        $this->identityResolver->registerProductMapping('20', 601);

        $this->whmcsDb->statement("INSERT INTO tblhosting (id, userid, packageid, server, regdate, domain, amount, billingcycle, domainstatus, username)
            VALUES (901, 10, 20, 1, '2025-01-01', 'example.org', 9.99, 'Monthly', 'Active', 'exorguser')");

        $batchId = 'batch-service-01';
        $stats = $this->migrator->migrateServices($batchId);

        $this->assertSame(1, $stats['total']);
        $this->assertSame(1, $stats['migrated']);
        $this->assertSame(0, $stats['quarantined']);

        // Verify service in services table
        $service = $this->targetDb->selectOne("SELECT * FROM services WHERE username = 'exorguser'");
        $this->assertNotNull($service);
        $this->assertSame(501, (int)$service['user_id']);
        $this->assertSame(601, (int)$service['product_id']);
        $this->assertSame('example.org', $service['domain']);
        $this->assertSame(1, (int)$service['is_adopted']);

        $meta = json_decode((string)$service['metadata_json'], true);
        $this->assertTrue($meta['provisioning_suppressed']);
        $this->assertSame('whmcs', $meta['adopted_from']);

        // Verify adopted identity recorded
        $identity = $this->adoptedRepo->findByExternalReference(AdoptedIdentityType::HOSTING_SERVICE, '1', 'exorguser');
        $this->assertNotNull($identity);
        $this->assertSame('901', $identity->getSourceId());
        $this->assertSame((int)$service['id'], $identity->getTargetEntityId());

        // Staging record
        $records = $this->stagingRepo->getBatchRecords($batchId);
        $this->assertCount(1, $records);
        $this->assertSame(StagingRecordStatus::MIGRATED, $records[0]->getStatus());
    }

    public function testDomainMigrationAdoptsDomainsNonDestructively(): void
    {
        $this->identityResolver->registerClientMapping('10', 501);

        $this->whmcsDb->statement("INSERT INTO tbldomains (id, userid, domain, recurringamount, registrar, registrationperiod, status)
            VALUES (801, 10, 'example.org', 14.50, 'resellerclub', 1, 'Active')");

        $batchId = 'batch-domain-01';
        $stats = $this->migrator->migrateDomains($batchId);

        $this->assertSame(1, $stats['total']);
        $this->assertSame(1, $stats['migrated']);
        $this->assertSame(0, $stats['quarantined']);

        // Verify domain in domains table
        $domain = $this->targetDb->selectOne("SELECT * FROM domains WHERE domain_name = 'example.org'");
        $this->assertNotNull($domain);
        $this->assertSame(501, (int)$domain['user_id']);
        $this->assertSame('example.org', $domain['domain_name']);
        $this->assertSame('resellerclub', $domain['registrar']);
        $this->assertSame(1, (int)$domain['is_adopted']);

        $meta = json_decode((string)$domain['metadata_json'], true);
        $this->assertTrue($meta['registrar_api_suppressed']);

        // Identity
        $identity = $this->adoptedRepo->findByExternalReference(AdoptedIdentityType::DOMAIN_REGISTRATION, 'resellerclub', 'example.org');
        $this->assertNotNull($identity);
        $this->assertSame('801', $identity->getSourceId());

        // Staging
        $records = $this->stagingRepo->getBatchRecords($batchId);
        $this->assertCount(1, $records);
        $this->assertSame(StagingRecordStatus::MIGRATED, $records[0]->getStatus());
    }

    public function testEndToEndMigrationCertifiesZeroSilentLoss(): void
    {
        $this->whmcsDb->statement("INSERT INTO tblcurrencies VALUES (1, 'USD', '$', '', 1.0, 1)");

        // 2 valid clients + 1 invalid client (malformed email)
        $this->whmcsDb->statement("INSERT INTO tblclients (id, firstname, lastname, companyname, email, currency, status)
            VALUES (1, 'John', 'Valid', 'Valid Org 1', 'john@valid.com', 1, 'Active')");
        $this->whmcsDb->statement("INSERT INTO tblclients (id, firstname, lastname, companyname, email, currency, status)
            VALUES (2, 'Jane', 'Valid', 'Valid Org 2', 'jane@valid.com', 1, 'Active')");
        $this->whmcsDb->statement("INSERT INTO tblclients (id, firstname, lastname, companyname, email, currency, status)
            VALUES (3, 'Bad', 'Client', 'Bad Org', 'not-an-email', 1, 'Active')");

        // 1 product group + 2 products
        $this->whmcsDb->statement("INSERT INTO tblproductgroups (id, name, slug) VALUES (1, 'Shared', 'shared')");
        $this->whmcsDb->statement("INSERT INTO tblproducts (id, gid, name, type) VALUES (10, 1, 'Silver Plan', 'hostingaccount')");
        $this->whmcsDb->statement("INSERT INTO tblproducts (id, gid, name, type) VALUES (20, 1, 'Gold Plan', 'hostingaccount')");

        // 2 services: 1 for valid client 1, 1 referencing quarantined client 3
        $this->whmcsDb->statement("INSERT INTO tblhosting (id, userid, packageid, server, domain, username, domainstatus)
            VALUES (101, 1, 10, 1, 'validdomain.com', 'valuser', 'Active')");
        $this->whmcsDb->statement("INSERT INTO tblhosting (id, userid, packageid, server, domain, username, domainstatus)
            VALUES (102, 3, 20, 1, 'orphan.com', 'badclientuser', 'Active')");

        // 2 domains: 1 valid domain for client 1, 1 invalid domain syntax (spaces in name)
        $this->whmcsDb->statement("INSERT INTO tbldomains (id, userid, domain, status, registrar)
            VALUES (201, 1, 'validdomain.com', 'Active', 'enom')");
        $this->whmcsDb->statement("INSERT INTO tbldomains (id, userid, domain, status, registrar)
            VALUES (202, 2, 'invalid domain name .com', 'Active', 'enom')");

        $batchId = 'e2e-whmcs-batch-01';
        $result = $this->migrator->migrateAll($batchId);

        // Certifications
        $this->assertTrue($result->isZeroSilentLossAchieved(), 'Zero Silent Loss invariant must be achieved');
        $this->assertTrue($result->isFullyTerminal(), 'Every record must reach a terminal state');
        $this->assertSame(0, $result->getAccountingReport()->getUnaccountedDiff());

        // Breakdown:
        // Clients: 3 staged (2 migrated, 1 quarantined)
        // Products: 2 staged (2 migrated, 0 quarantined)
        // Services: 2 staged (1 migrated, 1 quarantined due to unresolved client 3)
        // Domains: 2 staged (1 migrated, 1 quarantined due to invalid domain name syntax)
        // Total staged = 3 + 2 + 2 + 2 = 9
        // Total migrated = 2 + 2 + 1 + 1 = 6
        // Total quarantined = 1 + 0 + 1 + 1 = 3
        $this->assertSame(9, $result->getTotalStaged());
        $this->assertSame(6, $result->getTotalMigrated());
        $this->assertSame(3, $result->getTotalQuarantined());

        $report = $result->getAccountingReport();
        $this->assertSame(9, $report->getTotalStagedRecords());
        $this->assertSame(6, $report->getMigratedCount());
        $this->assertSame(3, $report->getQuarantinedCount());
        $this->assertSame(0, $report->getInFlightCount());

        // Ensure quarantined records have exact diagnostic reasons
        $quarantinedRecords = $this->stagingRepo->getBatchRecords($batchId, StagingRecordStatus::QUARANTINED);
        $this->assertCount(3, $quarantinedRecords);

        $reasons = array_map(fn($r) => $r->getQuarantineReason(), $quarantinedRecords);
        $this->assertTrue(count(array_filter($reasons, fn($r) => str_contains((string)$r, 'validation') || str_contains((string)$r, 'unmigrated') || str_contains((string)$r, 'invalid'))) === 3);
    }

    public function testSubaccountContactsMigration(): void
    {
        $this->whmcsDb->statement("INSERT INTO tblclients (id, firstname, lastname, companyname, email, status)
            VALUES (1, 'Main', 'User', 'Enterprise Corp', 'main@enterprise.com', 'Active')");

        $this->whmcsDb->statement("INSERT INTO tblcontacts (id, userid, firstname, lastname, email, subaccount)
            VALUES (11, 1, 'Sub', 'Contact', 'subaccount@enterprise.com', 1)");

        $batchId = 'batch-subaccount-01';
        $stats = $this->migrator->migrateClients($batchId, ['migrate_contacts' => true]);

        $this->assertSame(1, $stats['total']);
        $this->assertSame(1, $stats['migrated']);

        // Check subaccount user created
        $subUser = $this->targetDb->selectOne("SELECT * FROM users WHERE email = 'subaccount@enterprise.com'");
        $this->assertNotNull($subUser);
        $this->assertSame('Sub Contact', $subUser['name']);

        // Check added to enterprise organization
        $org = $this->targetDb->selectOne("SELECT * FROM organizations WHERE name = 'Enterprise Corp'");
        $this->assertNotNull($org);

        $subMember = $this->targetDb->selectOne("SELECT * FROM organization_members WHERE organization_id = ? AND user_id = ?", [(int)$org['id'], (int)$subUser['id']]);
        $this->assertNotNull($subMember);
        $this->assertSame('member', $subMember['role']);
    }

    public function testIdempotencyAndRerunHandling(): void
    {
        $this->identityResolver->registerClientMapping('10', 501);
        $this->identityResolver->registerProductMapping('20', 601);

        $this->whmcsDb->statement("INSERT INTO tblhosting (id, userid, packageid, server, domain, username, domainstatus)
            VALUES (901, 10, 20, 1, 'rerun-test.com', 'rerunuser', 'Active')");

        $batch1 = 'batch-run-1';
        $stats1 = $this->migrator->migrateServices($batch1);
        $this->assertSame(1, $stats1['migrated']);

        // Running adoption on second batch with same source ID should be idempotent
        $batch2 = 'batch-run-2';
        $stats2 = $this->migrator->migrateServices($batch2);
        $this->assertSame(1, $stats2['migrated']);
        $this->assertSame($stats1['service_ids']['901'], $stats2['service_ids']['901']);

        // Total services in DB should still be 1
        $count = $this->targetDb->selectOne("SELECT count(*) as cnt FROM services WHERE username = 'rerunuser'");
        $this->assertSame(1, (int)$count['cnt']);
    }
}
