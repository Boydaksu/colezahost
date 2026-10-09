<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Installer;

use Coleza\Domain\Installer\AdminBootstrapService;
use Coleza\Domain\Installer\AdminSetupDto;
use Coleza\Domain\Installer\BrandSetupDto;
use Coleza\Domain\Installer\BrandSetupService;
use Coleza\Domain\Installer\CronSetupService;
use Coleza\Domain\Installer\DatabaseConfig;
use Coleza\Domain\Installer\DatabaseSetupService;
use Coleza\Domain\Installer\EmailSetupService;
use Coleza\Domain\Installer\EmailTransportConfig;
use Coleza\Domain\Installer\EnvironmentRequirementChecker;
use Coleza\Domain\Installer\FreshInstallMigrationConfig;
use Coleza\Domain\Installer\FreshInstallMigrationModeService;
use Coleza\Domain\Installer\FreshInstallMigrationResult;
use Coleza\Domain\Installer\InstallationMode;
use Coleza\Domain\Installer\InstallationStep;
use Coleza\Domain\Installer\InstallerLock;
use Coleza\Domain\Installer\LocaleSetupDto;
use Coleza\Domain\Installer\LocaleSetupService;
use Coleza\Domain\Installer\WebInstallerService;
use Coleza\Domain\Migration\Adoption\AdoptedIdentityRepository;
use Coleza\Domain\Migration\Adoption\DomainAdoptionService;
use Coleza\Domain\Migration\Adoption\ProviderIdentityResolver;
use Coleza\Domain\Migration\Adoption\ServiceAdoptionService;
use Coleza\Domain\Migration\Execution\ConflictDetector;
use Coleza\Domain\Migration\Execution\MigrationCheckpointRepository;
use Coleza\Domain\Migration\Execution\MigrationExecutionOrchestrator;
use Coleza\Domain\Migration\Execution\QuarantineManager;
use Coleza\Domain\Migration\Hold\MigrationHoldRepository;
use Coleza\Domain\Migration\Hold\MigrationHoldService;
use Coleza\Domain\Migration\Hold\NotificationSuppressionManager;
use Coleza\Domain\Migration\Hold\SuppressedNotificationRepository;
use Coleza\Domain\Migration\Mapping\GenericMappingEngine;
use Coleza\Domain\Migration\Staging\DatabaseStagingRepository;
use Coleza\Domain\Migration\Staging\StagingPipelineService;
use Coleza\Domain\Migration\Validation\CanonicalValidationEngine;
use Coleza\Domain\Migration\Whmcs\Core\WhmcsCoreEntityExtractor;
use Coleza\Domain\Migration\Whmcs\Core\WhmcsCoreEntityMigrator;
use Coleza\Domain\Migration\Whmcs\Financial\WhmcsFinancialExtractor;
use Coleza\Domain\Migration\Whmcs\Financial\WhmcsFinancialMigrator;
use Coleza\Domain\Migration\Whmcs\Support\WhmcsSupportExtractor;
use Coleza\Domain\Migration\Whmcs\Support\WhmcsSupportMigrator;
use Coleza\Domain\Migration\Whmcs\WhmcsReadOnlyConnector;
use Coleza\Domain\Notifications\NotificationEngine;
use Coleza\Domain\Notifications\Templates\NotificationTemplateEngine;
use Coleza\Domain\Notifications\Transport\MemoryMailTransport;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class FreshInstallMigrationModeTest extends TestCase
{
    private string $tempLockFile;
    private Connection $freshTargetDb;
    private Connection $whmcsSourceDb;
    private WhmcsReadOnlyConnector $whmcsConnector;
    private MigrationExecutionOrchestrator $orchestrator;
    private FreshInstallMigrationModeService $migrationModeService;
    private WebInstallerService $installer;
    private MigrationHoldService $holdService;
    private NotificationSuppressionManager $suppressionManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempLockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fresh_mig_installer_' . uniqid() . '.lock';

        // 1. Fresh target database
        $targetPdo = new PDO('sqlite::memory:');
        $this->freshTargetDb = new Connection($targetPdo, 'sqlite');

        // 2. WHMCS Source database
        $whmcsPdo = new PDO('sqlite::memory:');
        $this->whmcsSourceDb = new Connection($whmcsPdo, 'sqlite');
        $this->whmcsConnector = new WhmcsReadOnlyConnector($this->whmcsSourceDb);

        $this->seedSourceWhmcsDatabase();

        // 3. Setup Migration Pipeline Components
        $stagingRepo = new DatabaseStagingRepository($this->freshTargetDb);
        $mappingEngine = new GenericMappingEngine();
        $validationEngine = new CanonicalValidationEngine();
        $stagingPipeline = new StagingPipelineService($stagingRepo, $mappingEngine, $validationEngine);

        $identityResolver = new ProviderIdentityResolver();
        $adoptedRepo = new AdoptedIdentityRepository($this->freshTargetDb);
        $serviceAdopter = new ServiceAdoptionService($this->freshTargetDb, $adoptedRepo, $identityResolver);
        $domainAdopter = new DomainAdoptionService($this->freshTargetDb, $adoptedRepo, $identityResolver);

        $coreExtractor = new WhmcsCoreEntityExtractor($this->whmcsConnector);
        $financialExtractor = new WhmcsFinancialExtractor($this->whmcsConnector);
        $supportExtractor = new WhmcsSupportExtractor($this->whmcsConnector);

        $coreMigrator = new WhmcsCoreEntityMigrator(
            targetDb: $this->freshTargetDb,
            whmcs: $this->whmcsConnector,
            stagingRepo: $stagingRepo,
            stagingPipeline: $stagingPipeline,
            mappingEngine: $mappingEngine,
            identityResolver: $identityResolver,
            serviceAdoptionService: $serviceAdopter,
            domainAdoptionService: $domainAdopter,
            adoptedIdentityRepo: $adoptedRepo,
            extractor: $coreExtractor
        );

        $financialMigrator = new WhmcsFinancialMigrator(
            targetDb: $this->freshTargetDb,
            whmcs: $this->whmcsConnector,
            stagingRepo: $stagingRepo,
            stagingPipeline: $stagingPipeline,
            identityResolver: $identityResolver,
            extractor: $financialExtractor
        );

        $supportMigrator = new WhmcsSupportMigrator(
            targetDb: $this->freshTargetDb,
            whmcs: $this->whmcsConnector,
            stagingRepo: $stagingRepo,
            stagingPipeline: $stagingPipeline,
            identityResolver: $identityResolver,
            extractor: $supportExtractor
        );

        $holdRepo = new MigrationHoldRepository($this->freshTargetDb);
        $this->holdService = new MigrationHoldService($holdRepo);

        $suppressionRepo = new SuppressedNotificationRepository($this->freshTargetDb);
        $mailTransport = new MemoryMailTransport();
        $templateEngine = new NotificationTemplateEngine();
        $notificationEngine = new NotificationEngine($mailTransport, $templateEngine);
        $this->suppressionManager = new NotificationSuppressionManager($this->holdService, $suppressionRepo, $notificationEngine);

        $checkpointRepo = new MigrationCheckpointRepository($this->freshTargetDb);
        $conflictDetector = new ConflictDetector($this->freshTargetDb);
        $quarantineManager = new QuarantineManager($stagingRepo, $stagingPipeline);

        $this->orchestrator = new MigrationExecutionOrchestrator(
            targetDb: $this->freshTargetDb,
            coreMigrator: $coreMigrator,
            financialMigrator: $financialMigrator,
            supportMigrator: $supportMigrator,
            conflictDetector: $conflictDetector,
            checkpointRepo: $checkpointRepo,
            quarantineManager: $quarantineManager,
            stagingRepo: $stagingRepo,
            validationEngine: $validationEngine,
            coreExtractor: $coreExtractor,
            financialExtractor: $financialExtractor,
            supportExtractor: $supportExtractor,
            holdService: $this->holdService,
            suppressionManager: $this->suppressionManager
        );

        $this->migrationModeService = new FreshInstallMigrationModeService($this->orchestrator);

        // 4. Setup WebInstaller
        $reqChecker = new EnvironmentRequirementChecker();
        $reqChecker->setDirectoryWritableOverride('storage', true);
        $reqChecker->setDirectoryWritableOverride('config', true);
        $reqChecker->setDirectoryWritableOverride('logs', true);

        $lock = new InstallerLock($this->tempLockFile);

        $this->installer = new WebInstallerService(
            requirementChecker: $reqChecker,
            databaseSetupService: new DatabaseSetupService(),
            adminBootstrapService: new AdminBootstrapService(),
            localeSetupService: new LocaleSetupService(),
            brandSetupService: new BrandSetupService(),
            emailSetupService: new EmailSetupService(),
            cronSetupService: new CronSetupService(),
            installerLock: $lock,
            connection: $this->freshTargetDb,
            freshMigrationService: $this->migrationModeService
        );
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempLockFile)) {
            unlink($this->tempLockFile);
        }

        parent::tearDown();
    }

    private function seedSourceWhmcsDatabase(): void
    {
        $this->whmcsSourceDb->statement('CREATE TABLE tblconfiguration (setting TEXT PRIMARY KEY, value TEXT)');
        $this->whmcsSourceDb->statement("INSERT INTO tblconfiguration (setting, value) VALUES ('Version', '8.9.0')");

        $this->whmcsSourceDb->statement('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, firstname TEXT, lastname TEXT, companyname TEXT, email TEXT, address1 TEXT, address2 TEXT, city TEXT, state TEXT, postcode TEXT, country TEXT, phonenumber TEXT, currency INT, status TEXT, datecreated TEXT, credit REAL DEFAULT 0.0)');
        $this->whmcsSourceDb->statement("INSERT INTO tblclients (id, firstname, lastname, companyname, email, address1, city, state, postcode, country, phonenumber, currency, status, datecreated, credit) VALUES (1, 'Alice', 'Smith', 'Wonder Corp', 'alice@example.com', '123 Main St', 'City', 'State', '10001', 'US', '+1234567890', 1, 'Active', '2025-01-01', 50.00)");

        $this->whmcsSourceDb->statement('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT, prefix TEXT, suffix TEXT, format INT, rate REAL)');
        $this->whmcsSourceDb->statement("INSERT INTO tblcurrencies (id, code, prefix, suffix, format, rate) VALUES (1, 'USD', '$', ' USD', 1, 1.0)");

        $this->whmcsSourceDb->statement('CREATE TABLE tblproducts (id INTEGER PRIMARY KEY, gid INT, type TEXT, name TEXT, description TEXT, paytype TEXT, servertype TEXT, autosetup TEXT)');
        $this->whmcsSourceDb->statement("INSERT INTO tblproducts (id, gid, type, name, description, paytype, servertype, autosetup) VALUES (1, 1, 'hostingaccount', 'Starter Hosting', 'Desc', 'recurring', 'cpanel', 'payment')");

        $this->whmcsSourceDb->statement('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, orderid INT, packageid INT, server INT, regdate TEXT, domain TEXT, paymentmethod TEXT, firstpaymentamount REAL, amount REAL, billingcycle TEXT, nextduedate TEXT, domainstatus TEXT, username TEXT, password TEXT, dedicatedip TEXT, assignedips TEXT, diskusage REAL, disklimit REAL, bwusage REAL, bwlimit REAL, lastupdate TEXT)');
        $this->whmcsSourceDb->statement("INSERT INTO tblhosting (id, userid, orderid, packageid, server, regdate, domain, paymentmethod, firstpaymentamount, amount, billingcycle, nextduedate, domainstatus, username) VALUES (1, 1, 1, 1, 1, '2025-01-01', 'alicesmith.com', 'stripe', 10.00, 10.00, 'Monthly', '2025-02-01', 'Active', 'aliceusr')");

        $this->whmcsSourceDb->statement('CREATE TABLE tbldomains (id INTEGER PRIMARY KEY, userid INT, orderid INT, type TEXT, registrationdate TEXT, domain TEXT, firstpaymentamount REAL, recurringamount REAL, registrar TEXT, registrationperiod INT, expirydate TEXT, nextduedate TEXT, status TEXT, subscriptionid TEXT, dnsmanagement INT, emailforwarding INT, idprotection INT, donotrenew INT)');
        $this->whmcsSourceDb->statement("INSERT INTO tbldomains (id, userid, orderid, type, registrationdate, domain, firstpaymentamount, recurringamount, registrar, registrationperiod, expirydate, nextduedate, status) VALUES (1, 1, 1, 'Register', '2025-01-01', 'alicesmith.com', 12.00, 12.00, 'enom', 1, '2026-01-01', '2026-01-01', 'Active')");

        $this->whmcsSourceDb->statement('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum TEXT, date TEXT, duedate TEXT, datepaid TEXT, subtotal REAL, credit REAL, tax REAL, tax2 REAL, total REAL, taxrate REAL, taxrate2 REAL, status TEXT, paymentmethod TEXT, notes TEXT)');
        $this->whmcsSourceDb->statement("INSERT INTO tblinvoices (id, userid, invoicenum, date, duedate, datepaid, subtotal, credit, tax, tax2, total, taxrate, taxrate2, status, paymentmethod) VALUES (1, 1, 'INV-1001', '2025-01-01', '2025-01-15', '2025-01-02', 22.00, 0, 0, 0, 22.00, 0, 0, 'Paid', 'stripe')");

        $this->whmcsSourceDb->statement('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, description TEXT, amount REAL, taxed INT, duedate TEXT, paymentmethod TEXT, notes TEXT)');
        $this->whmcsSourceDb->statement("INSERT INTO tblinvoiceitems (id, invoiceid, userid, type, relid, description, amount, taxed) VALUES (1, 1, 1, 'Hosting', 1, 'Starter Hosting Renewal', 22.00, 0)");

        $this->whmcsSourceDb->statement('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, currency INT, gateway TEXT, date TEXT, description TEXT, amountin REAL, fees REAL, amountout REAL, transid TEXT, invoiceid INT, refundid INT)');
        $this->whmcsSourceDb->statement("INSERT INTO tblaccounts (id, userid, currency, gateway, date, description, amountin, fees, amountout, transid, invoiceid) VALUES (1, 1, 1, 'stripe', '2025-01-02 10:00:00', 'Payment', 22.00, 0.50, 0, 'ch_test_123', 1)");

        $this->whmcsSourceDb->statement('CREATE TABLE tblcredit (id INTEGER PRIMARY KEY, clientid INT, date TEXT, description TEXT, amount REAL, relid INT)');
        $this->whmcsSourceDb->statement("INSERT INTO tblcredit (id, clientid, date, description, amount, relid) VALUES (1, 1, '2025-01-01 12:00:00', 'Credit Added', 50.00, 0)");

        $this->whmcsSourceDb->statement('CREATE TABLE tblticketdepartments (id INTEGER PRIMARY KEY, name TEXT, description TEXT, email TEXT, hidden INT, order_num INT)');
        $this->whmcsSourceDb->statement("INSERT INTO tblticketdepartments (id, name, description, email, hidden, order_num) VALUES (1, 'Technical Support', 'Tech issues', 'support@example.com', 0, 1)");

        $this->whmcsSourceDb->statement('CREATE TABLE tbltickets (id INTEGER PRIMARY KEY, did INT, userid INT, contactid INT, name TEXT, email TEXT, date TEXT, title TEXT, message TEXT, status TEXT, urgency TEXT, admin TEXT, attachment TEXT, lastreply TEXT, flag INT, clientunread INT, adminunread INT)');
        $this->whmcsSourceDb->statement("INSERT INTO tbltickets (id, did, userid, contactid, name, email, date, title, message, status, urgency, admin, attachment, lastreply, flag, clientunread, adminunread) VALUES (1, 1, 1, 0, 'Alice Smith', 'alice@example.com', '2025-01-05 10:00:00', 'Help with setup', 'Cannot connect', 'Open', 'Medium', '', '', '2025-01-05 10:00:00', 0, 0, 1)");
    }

    public function testInstallationModeEnum(): void
    {
        $this->assertSame('fresh', InstallationMode::FRESH->value);
        $this->assertSame('migration', InstallationMode::MIGRATION->value);
    }

    public function testTestSourceConnectionDetectsInvalidDatabase(): void
    {
        $config = new FreshInstallMigrationConfig(
            sourceType: 'whmcs',
            connectionConfig: [
                'driver' => 'sqlite',
                'database' => ':memory:', // empty database
            ]
        );

        $testResult = $this->migrationModeService->testSourceConnection($config);

        $this->assertFalse($testResult['reachable']);
        $this->assertStringContainsString('recognizable WHMCS tables', (string) $testResult['error']);
    }

    public function testFreshInstallWithMigrationStepE2E(): void
    {
        // 1. Run database initialization on target
        $dbSetup = new DatabaseSetupService();
        $dbSetup->initializeCoreSchema($this->freshTargetDb);

        // 2. Setup Super Admin
        $this->installer->setupAdmin(new AdminSetupDto(
            email: 'admin@myhost.net',
            password: 'StrongPassword123!',
            firstName: 'Super',
            lastName: 'Admin'
        ));

        // 3. Setup Localization & Brand
        $this->installer->setupLocale(new LocaleSetupDto(
            defaultLocale: 'en',
            defaultCurrency: 'USD',
            timezone: 'UTC'
        ));

        $this->installer->setupBrand(new BrandSetupDto(
            brandName: 'CloudPro Hosting',
            companyName: 'CloudPro LLC',
            supportEmail: 'support@cloudpro.net'
        ));

        // 4. Setup Email & Cron
        $this->installer->setupEmail(new EmailTransportConfig(driver: 'memory'));
        $this->installer->setupCron();

        $this->assertSame(InstallationStep::COMPLETED, $this->installer->getCurrentStep());

        // 5. User chooses Fresh-Install Migration Mode
        $migConfig = new FreshInstallMigrationConfig(
            sourceType: 'whmcs',
            connectionConfig: [
                'driver' => 'sqlite',
                'database' => ':memory:',
            ],
            autoHold: true,
            suppressNotifications: true,
            conflictStrategy: 'quarantine'
        );

        // Execute migration step
        $migResult = $this->installer->executeMigrationStep($migConfig, 'batch_fresh_test_001');

        $this->assertTrue($migResult->isSuccess(), 'Migration step failed: ' . ($migResult->getErrorMessage() ?? 'unknown'));
        $this->assertSame('whmcs', $migResult->getSourceType());
        $this->assertGreaterThan(0, $migResult->getTotalMigrated());
        $this->assertTrue($migResult->isMigrationHoldEngaged());
        $this->assertTrue($migResult->areNotificationsSuppressed());

        // Target database should contain migrated clients
        $clientRow = $this->freshTargetDb->selectOne('SELECT count(*) as cnt FROM users WHERE email = ?', ['alice@example.com']);
        $clientCount = (int) ($clientRow['cnt'] ?? 0);
        $this->assertSame(1, $clientCount);

        // Global migration hold should be active
        $this->assertTrue($this->holdService->isGlobalHoldActive());

        // Step should transition to COMPLETED
        $this->assertSame(InstallationStep::COMPLETED, $this->installer->getCurrentStep());

        // 6. Finalize installation
        $summary = $this->installer->finalizeInstallation([
            'installation_mode' => InstallationMode::MIGRATION->value,
            'imported_records' => $migResult->getTotalMigrated(),
        ]);

        $this->assertTrue($this->installer->isLocked());
        $this->assertSame('CloudPro Hosting', $summary->getBrandName());
        $this->assertSame('admin@myhost.net', $summary->getAdminEmail());
        $this->assertSame(InstallationMode::MIGRATION->value, $summary->getExtra()['installation_mode']);
        $this->assertSame($migResult->getTotalMigrated(), $summary->getExtra()['imported_records']);
    }

    public function testExecutionFailsWhenOrchestratorNotConfigured(): void
    {
        $serviceWithoutOrchestrator = new FreshInstallMigrationModeService(null);
        $this->installer->setFreshMigrationService($serviceWithoutOrchestrator);

        $dbSetup = new DatabaseSetupService();
        $dbSetup->initializeCoreSchema($this->freshTargetDb);

        $config = new FreshInstallMigrationConfig('whmcs', []);
        $result = $this->installer->executeMigrationStep($config);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('orchestrator is not configured', (string) $result->getErrorMessage());
    }
}
