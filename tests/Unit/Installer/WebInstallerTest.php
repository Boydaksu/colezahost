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
use Coleza\Domain\Installer\Exceptions\InstallationLockedException;
use Coleza\Domain\Installer\Exceptions\PrerequisiteNotMetException;
use Coleza\Domain\Installer\InstallationStep;
use Coleza\Domain\Installer\InstallerLock;
use Coleza\Domain\Installer\LocaleSetupDto;
use Coleza\Domain\Installer\LocaleSetupService;
use Coleza\Domain\Installer\WebInstallerService;
use Coleza\Domain\Notifications\Transport\MemoryMailTransport;
use Coleza\Foundation\Database\Connection;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class WebInstallerTest extends TestCase
{
    private string $tempLockFile;
    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempLockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'coleza_test_installer_' . uniqid() . '.lock';

        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempLockFile)) {
            unlink($this->tempLockFile);
        }

        parent::tearDown();
    }

    private function createInstaller(
        ?EnvironmentRequirementChecker $checker = null,
        ?InstallerLock $lock = null
    ): WebInstallerService {
        $reqChecker = $checker ?? new EnvironmentRequirementChecker();
        // Set temp directory as writable for test
        $reqChecker->setDirectoryWritableOverride('storage', true);
        $reqChecker->setDirectoryWritableOverride('config', true);
        $reqChecker->setDirectoryWritableOverride('logs', true);

        $dbService = new DatabaseSetupService();
        $adminService = new AdminBootstrapService();
        $localeService = new LocaleSetupService();
        $brandService = new BrandSetupService();
        $emailService = new EmailSetupService();
        $cronService = new CronSetupService();
        $installerLock = $lock ?? new InstallerLock($this->tempLockFile, $this->db);

        $installer = new WebInstallerService(
            requirementChecker: $reqChecker,
            databaseSetupService: $dbService,
            adminBootstrapService: $adminService,
            localeSetupService: $localeService,
            brandSetupService: $brandService,
            emailSetupService: $emailService,
            cronSetupService: $cronService,
            installerLock: $installerLock,
            connection: $this->db
        );

        return $installer;
    }

    public function testRequirementsCheckerPassesOnCurrentEnvironment(): void
    {
        $checker = new EnvironmentRequirementChecker();
        $checker->setDirectoryWritableOverride('storage', true);
        $checker->setDirectoryWritableOverride('config', true);
        $checker->setDirectoryWritableOverride('logs', true);

        $report = $checker->check();

        $this->assertTrue($report->isInstallable());
        $this->assertEmpty($report->getMissingRequired());
        $this->assertGreaterThan(5, count($report->getItems()));

        $phpItem = $report->getItem('php_version');
        $this->assertNotNull($phpItem);
        $this->assertTrue($phpItem->isPassed());
    }

    public function testRequirementsCheckerFailsWhenRequiredExtensionMissing(): void
    {
        $checker = new EnvironmentRequirementChecker();
        $checker->setExtensionOverride('mbstring', false);

        $report = $checker->check();

        $this->assertFalse($report->isInstallable());
        $this->assertNotEmpty($report->getMissingRequired());

        $mbItem = $report->getItem('ext_mbstring');
        $this->assertNotNull($mbItem);
        $this->assertFalse($mbItem->isPassed());
    }

    public function testRequirementsCheckerFailsWhenPhpVersionTooLow(): void
    {
        $checker = new EnvironmentRequirementChecker();
        $checker->setPhpVersionOverride('8.1.9');

        $report = $checker->check();

        $this->assertFalse($report->isInstallable());
        $phpItem = $report->getItem('php_version');
        $this->assertNotNull($phpItem);
        $this->assertFalse($phpItem->isPassed());
    }

    public function testDatabaseSetupTestConnectionFailure(): void
    {
        $dbService = new DatabaseSetupService();
        // Unreachable port / host
        $config = new DatabaseConfig(
            driver: 'mysql',
            host: '127.0.0.1',
            port: 65530,
            database: 'non_existent',
            username: 'bad_user',
            password: 'bad_password'
        );

        $result = $dbService->testConnection($config);

        $this->assertFalse($result->isSuccess());
        $this->assertNotEmpty($result->getMessage());
    }

    public function testDatabaseSetupInitializesCoreTables(): void
    {
        $dbService = new DatabaseSetupService();
        $initResult = $dbService->initializeCoreSchema($this->db);

        $this->assertTrue($initResult['success']);
        $this->assertContains('users', $initResult['tables_initialized']);
        $this->assertContains('roles', $initResult['tables_initialized']);
        $this->assertContains('brands', $initResult['tables_initialized']);
        $this->assertContains('system_settings', $initResult['tables_initialized']);

        // Super admin role must exist
        $role = $this->db->selectOne('SELECT * FROM roles WHERE name = "super_admin"');
        $this->assertNotNull($role);
        $this->assertSame('Super Administrator', $role['display_name']);
    }

    public function testAdminSetupDtoValidation(): void
    {
        // Invalid email
        $this->expectException(InvalidArgumentException::class);
        new AdminSetupDto('invalid-email', 'ValidPass123!', 'John', 'Doe');
    }

    public function testAdminSetupDtoPasswordPolicy(): void
    {
        // Password too short (< 10 chars)
        $this->expectException(InvalidArgumentException::class);
        new AdminSetupDto('admin@domain.com', 'Short1!', 'John', 'Doe');
    }

    public function testAdminBootstrapServiceCreatesSuperAdmin(): void
    {
        $dbService = new DatabaseSetupService();
        $dbService->initializeCoreSchema($this->db);

        $adminService = new AdminBootstrapService();
        $dto = new AdminSetupDto('admin@company.com', 'SecureP@ssw0rd123!', 'John', 'Smith');

        $admin = $adminService->bootstrapAdmin($this->db, $dto);

        $this->assertGreaterThan(0, $admin['id']);
        $this->assertSame('admin@company.com', $admin['email']);
        $this->assertSame('super_admin', $admin['role']);

        // Verify password hash verification
        $userRow = $this->db->selectOne('SELECT password_hash FROM users WHERE id = ?', [$admin['id']]);
        $this->assertNotNull($userRow);
        $this->assertTrue(password_verify('SecureP@ssw0rd123!', (string) $userRow['password_hash']));

        // Verify user_roles table mapping
        $roleRow = $this->db->selectOne('SELECT role_id FROM user_roles WHERE user_id = ?', [$admin['id']]);
        $this->assertNotNull($roleRow);
    }

    public function testLocaleAndBrandSetup(): void
    {
        $dbService = new DatabaseSetupService();
        $dbService->initializeCoreSchema($this->db);

        // 1. Locale Setup
        $localeService = new LocaleSetupService();
        $localeDto = new LocaleSetupDto(
            defaultLocale: 'tr',
            timezone: 'Europe/Istanbul',
            defaultCurrency: 'TRY',
            dateFormat: 'd.m.Y'
        );

        $localeResult = $localeService->configureLocale($this->db, $localeDto);
        $this->assertSame('tr', $localeResult['app.locale']);
        $this->assertSame('TRY', $localeResult['app.currency']);

        // Check system settings
        $currSetting = $this->db->selectOne('SELECT setting_value FROM system_settings WHERE setting_key = "app.currency"');
        $this->assertSame('TRY', $currSetting['setting_value']);

        // 2. Brand Setup
        $brandService = new BrandSetupService();
        $brandDto = new BrandSetupDto(
            companyName: 'Coleza Bulut Bilisim A.S.',
            brandName: 'Coleza Cloud',
            supportEmail: 'destek@coleza.com',
            domain: 'coleza.com.tr',
            address: 'Buyukdere Cad. No:199 Istanbul',
            taxNumber: '1234567890'
        );

        $brandResult = $brandService->configureBrand($this->db, $brandDto, 'TRY', 'tr');
        $this->assertSame('Coleza Cloud', $brandResult['brand_name']);
        $this->assertSame('Coleza Bulut Bilisim A.S.', $brandResult['company_name']);

        $brandRow = $this->db->selectOne('SELECT * FROM brands WHERE id = ?', [$brandResult['brand_id']]);
        $this->assertNotNull($brandRow);
        $this->assertSame(1, (int) $brandRow['is_active']);
    }

    public function testEmailSetupAndLiveTestDelivery(): void
    {
        $dbService = new DatabaseSetupService();
        $dbService->initializeCoreSchema($this->db);

        $emailService = new EmailSetupService();
        $emailConfig = new EmailTransportConfig(
            driver: 'memory',
            fromAddress: 'notifications@coleza.com',
            fromName: 'Coleza Mail System'
        );

        $settings = $emailService->configureEmail($this->db, $emailConfig);
        $this->assertSame('memory', $settings['mail.driver']);
        $this->assertSame('notifications@coleza.com', $settings['mail.from_address']);

        // Test delivery
        $mailTransport = new MemoryMailTransport();
        $deliveryResult = $emailService->sendTestEmail($emailConfig, 'admin@coleza.com', $mailTransport);

        $this->assertTrue($deliveryResult->isSuccess());
        $this->assertCount(1, $mailTransport->getSentMessages());
        $this->assertSame('admin@coleza.com', $mailTransport->getSentMessages()[0]->getRecipientEmail());
    }

    public function testCronSetupAndTokenValidation(): void
    {
        $dbService = new DatabaseSetupService();
        $dbService->initializeCoreSchema($this->db);

        $cronService = new CronSetupService();
        $cronResult = $cronService->setupCron(
            db: $this->db,
            customToken: null,
            appUrl: 'https://hosting.myprovider.com',
            basePath: '/var/www/colezahost'
        );

        $this->assertNotEmpty($cronResult['token']);
        $this->assertStringContainsString('* * * * * php /var/www/colezahost/bin/coleza schedule:run', $cronResult['cli_command']);
        $this->assertStringContainsString('https://hosting.myprovider.com/api/cron/run?token=', $cronResult['webhook_url']);

        // Validate token
        $this->assertTrue($cronService->validateCronToken($this->db, $cronResult['token']));
        $this->assertFalse($cronService->validateCronToken($this->db, 'wrong_token'));

        // Record execution
        $cronService->recordCronExecution($this->db, 45, 'success', 'All schedules executed.');
        $runRow = $this->db->selectOne('SELECT * FROM cron_runs ORDER BY id DESC LIMIT 1');
        $this->assertNotNull($runRow);
        $this->assertSame('success', $runRow['status']);
        $this->assertSame(45, (int) $runRow['duration_ms']);
    }

    public function testCompleteEndToEndInstallationWizardWorkflowAndLockProtection(): void
    {
        $installer = $this->createInstaller();

        $this->assertFalse($installer->isLocked());
        $this->assertSame(InstallationStep::REQUIREMENTS, $installer->getCurrentStep());

        // Step 1: Requirements
        $reqReport = $installer->checkRequirements();
        $this->assertTrue($reqReport->isInstallable());
        $this->assertSame(InstallationStep::DATABASE, $installer->getCurrentStep());

        // Step 2: Database
        $dbConfig = DatabaseConfig::sqlite(':memory:');
        $dbResult = $installer->setupDatabase($dbConfig);
        $this->assertTrue($dbResult['connection_test']->isSuccess());
        $this->assertSame(InstallationStep::ADMIN, $installer->getCurrentStep());

        // Step 3: Admin
        $adminDto = new AdminSetupDto('root@hostingpro.io', 'MasterP@ssw0rd99!', 'Super', 'Admin');
        $adminResult = $installer->setupAdmin($adminDto);
        $this->assertSame('root@hostingpro.io', $adminResult['email']);
        $this->assertSame(InstallationStep::LOCALIZATION, $installer->getCurrentStep());

        // Step 4: Locale
        $localeDto = new LocaleSetupDto('en', 'UTC', 'USD', 'Y-m-d');
        $localeResult = $installer->setupLocale($localeDto);
        $this->assertSame('en', $localeResult['app.locale']);
        $this->assertSame(InstallationStep::BRAND, $installer->getCurrentStep());

        // Step 5: Brand
        $brandDto = new BrandSetupDto(
            companyName: 'Hosting Pro Global Ltd',
            brandName: 'Hosting Pro',
            supportEmail: 'support@hostingpro.io',
            domain: 'hostingpro.io'
        );
        $brandResult = $installer->setupBrand($brandDto);
        $this->assertSame('Hosting Pro', $brandResult['brand_name']);
        $this->assertSame(InstallationStep::EMAIL, $installer->getCurrentStep());

        // Step 6: Email
        $mailTransport = new MemoryMailTransport();
        $emailConfig = new EmailTransportConfig('memory', 'noreply@hostingpro.io', 'Hosting Pro Mail');
        $emailResult = $installer->setupEmail($emailConfig, 'root@hostingpro.io', $mailTransport);
        $this->assertNotNull($emailResult['test_result']);
        $this->assertTrue($emailResult['test_result']->isSuccess());
        $this->assertSame(InstallationStep::CRON, $installer->getCurrentStep());

        // Step 7: Cron
        $cronResult = $installer->setupCron('https://hostingpro.io', '/var/www/colezahost');
        $this->assertNotEmpty($cronResult['token']);
        $this->assertSame(InstallationStep::COMPLETED, $installer->getCurrentStep());

        // Finalize & Lock
        $summary = $installer->finalizeInstallation([
            'app_version' => '1.0.0',
            'installer_environment' => 'production',
        ]);

        $this->assertSame('1.0.0', $summary->getAppVersion());
        $this->assertSame('root@hostingpro.io', $summary->getAdminEmail());
        $this->assertSame('Hosting Pro', $summary->getBrandName());
        $this->assertSame($this->tempLockFile, $summary->getLockFilePath());

        // Installer Lock Engagement Verification
        $this->assertTrue($installer->isLocked());
        $this->assertTrue(file_exists($this->tempLockFile));

        $lockMeta = $installer->getInstallerLock()->getMetadata();
        $this->assertNotNull($lockMeta);
        $this->assertSame('root@hostingpro.io', $lockMeta['admin_email']);
        $this->assertNotEmpty($lockMeta['sha256_checksum']);

        // Re-entry Prevention: any subsequent installer action MUST throw InstallationLockedException
        $this->expectException(InstallationLockedException::class);
        $installer->checkRequirements();
    }
}
