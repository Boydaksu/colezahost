<?php

declare(strict_types=1);

namespace Coleza\Domain\Installer;

use Coleza\Domain\Installer\Exceptions\InstallationLockedException;
use Coleza\Domain\Installer\Exceptions\PrerequisiteNotMetException;
use Coleza\Domain\Notifications\Transport\MailerInterface;
use Coleza\Foundation\Database\Connection;

/**
 * Master service orchestrating the multi-step Web Installer wizard.
 * Enforces pre-flight requirements, schema installation, admin bootstrapping,
 * regional configuration, active brand setup, email transport, automation scheduler,
 * and cryptographic lock sealing.
 */
final class WebInstallerService
{
    private ?Connection $connection = null;
    private InstallationStep $currentStep = InstallationStep::REQUIREMENTS;

    /** @var array<string, mixed> */
    private array $sessionState = [];

    public function __construct(
        private EnvironmentRequirementChecker $requirementChecker,
        private DatabaseSetupService $databaseSetupService,
        private AdminBootstrapService $adminBootstrapService,
        private LocaleSetupService $localeSetupService,
        private BrandSetupService $brandSetupService,
        private EmailSetupService $emailSetupService,
        private CronSetupService $cronSetupService,
        private InstallerLock $installerLock,
        ?Connection $connection = null,
        private ?FreshInstallMigrationModeService $freshMigrationService = null
    ) {
        $this->connection = $connection;
    }

    public function isLocked(): bool
    {
        return $this->installerLock->isLocked();
    }

    public function getCurrentStep(): InstallationStep
    {
        return $this->currentStep;
    }

    public function getConnection(): ?Connection
    {
        return $this->connection;
    }

    public function setConnection(Connection $connection): void
    {
        $this->connection = $connection;
    }

    /**
     * Step 1: Evaluates system runtime environment, extensions, and writable folders.
     */
    public function checkRequirements(): SystemRequirementsReport
    {
        $this->assertNotLocked();

        $report = $this->requirementChecker->check();
        if ($report->isInstallable()) {
            $this->currentStep = InstallationStep::DATABASE;
        }

        return $report;
    }

    /**
     * Step 2: Tests connectivity and installs core baseline schema.
     *
     * @return array{connection_test: ConnectionTestResult, tables_initialized: list<string>}
     */
    public function setupDatabase(DatabaseConfig $config): array
    {
        $this->assertNotLocked();
        if ($config->getPrefix() !== '') {
            throw new \InvalidArgumentException('Application installation requires a dedicated database without a table prefix.');
        }

        // 1. Verify requirements pass first
        $reqReport = $this->requirementChecker->check();
        if (!$reqReport->isInstallable()) {
            $missing = [];
            foreach ($reqReport->getMissingRequired() as $m) {
                $missing[] = $m->getName();
            }
            throw PrerequisiteNotMetException::withUnmet($missing);
        }

        // 2. Test database connection
        $testResult = $this->databaseSetupService->testConnection($config);
        if (!$testResult->isSuccess()) {
            return [
                'connection_test' => $testResult,
                'tables_initialized' => [],
            ];
        }

        // 3. Establish connection and initialize schema
        $this->connection = $this->databaseSetupService->createConnection($config);
        $schemaResult = $this->databaseSetupService->initializeApplicationSchema($this->connection);

        $this->sessionState['db_driver'] = $config->getDriver();
        $this->sessionState['db_database'] = $config->getDatabase();
        $this->currentStep = InstallationStep::ADMIN;

        return [
            'connection_test' => $testResult,
            'tables_initialized' => $schemaResult['tables_initialized'],
        ];
    }

    /**
     * Step 3: Bootstraps the root Super Administrator account.
     *
     * @return array{id: int, email: string, first_name: string, last_name: string, role: string}
     */
    public function setupAdmin(AdminSetupDto $dto): array
    {
        $this->assertNotLocked();
        $this->assertDatabaseConnected();

        /** @var Connection $db */
        $db = $this->connection;
        $result = $this->adminBootstrapService->bootstrapAdmin($db, $dto);

        $this->sessionState['admin_id'] = $result['id'];
        $this->sessionState['admin_email'] = $result['email'];
        $this->currentStep = InstallationStep::LOCALIZATION;

        return $result;
    }

    /**
     * Step 4: Configures system locale, timezone, and operational currency.
     *
     * @return array<string, string>
     */
    public function setupLocale(LocaleSetupDto $dto): array
    {
        $this->assertNotLocked();
        $this->assertDatabaseConnected();

        /** @var Connection $db */
        $db = $this->connection;
        $result = $this->localeSetupService->configureLocale($db, $dto);

        $this->sessionState['default_locale'] = $dto->getDefaultLocale();
        $this->sessionState['default_currency'] = $dto->getDefaultCurrency();
        $this->sessionState['timezone'] = $dto->getTimezone();
        $this->currentStep = InstallationStep::BRAND;

        return $result;
    }

    /**
     * Step 5: Configures hosting company identity and active brand profile.
     *
     * @return array{brand_id: int, organization_id: int, brand_name: string, company_name: string}
     */
    public function setupBrand(BrandSetupDto $dto): array
    {
        $this->assertNotLocked();
        $this->assertDatabaseConnected();

        $currency = (string) ($this->sessionState['default_currency'] ?? 'USD');
        $locale = (string) ($this->sessionState['default_locale'] ?? 'en');

        /** @var Connection $db */
        $db = $this->connection;
        $result = $this->brandSetupService->configureBrand($db, $dto, $currency, $locale);

        $this->sessionState['brand_id'] = $result['brand_id'];
        $this->sessionState['brand_name'] = $result['brand_name'];
        $this->sessionState['company_name'] = $result['company_name'];
        $this->currentStep = InstallationStep::EMAIL;

        return $result;
    }

    /**
     * Step 6: Configures outbound mail transport and optionally executes live delivery test.
     *
     * @return array{settings: array<string, string>, test_result: ?\Coleza\Domain\Notifications\Delivery\DeliveryResult}
     */
    public function setupEmail(
        EmailTransportConfig $config,
        ?string $testRecipient = null,
        ?MailerInterface $mailer = null
    ): array {
        $this->assertNotLocked();
        $this->assertDatabaseConnected();

        /** @var Connection $db */
        $db = $this->connection;
        $settings = $this->emailSetupService->configureEmail($db, $config);

        $testResult = null;
        if ($testRecipient !== null && trim($testRecipient) !== '') {
            $testResult = $this->emailSetupService->sendTestEmail($config, $testRecipient, $mailer);
        }

        $this->sessionState['mail_driver'] = $config->getDriver();
        $this->currentStep = InstallationStep::CRON;

        return [
            'settings' => $settings,
            'test_result' => $testResult,
        ];
    }

    /**
     * Step 7: Configures background automation scheduler token and crontab command snippet.
     *
     * @return array{token: string, cli_command: string, webhook_url: string}
     */
    public function setupCron(?string $appUrl = null, ?string $basePath = null): array
    {
        $this->assertNotLocked();
        $this->assertDatabaseConnected();

        /** @var Connection $db */
        $db = $this->connection;
        $result = $this->cronSetupService->setupCron($db, null, $appUrl, $basePath);

        $this->sessionState['cron_token'] = $result['token'];
        $this->sessionState['cron_cli_command'] = $result['cli_command'];
        $this->currentStep = InstallationStep::COMPLETED;

        return $result;
    }

    /**
     * Optional Step: Executes fresh-install migration import prior to finalizing installation.
     * Transitions step to MIGRATION, imports legacy dataset, preserves migration hold, and advances to COMPLETED.
     */
    public function executeMigrationStep(FreshInstallMigrationConfig $config, ?string $batchId = null): FreshInstallMigrationResult
    {
        $this->assertNotLocked();
        $this->assertDatabaseConnected();

        if ($this->freshMigrationService === null) {
            throw new \RuntimeException('Fresh install migration service is not configured.');
        }

        $this->currentStep = InstallationStep::MIGRATION;

        /** @var Connection $db */
        $db = $this->connection;
        $result = $this->freshMigrationService->executeFreshMigration($db, $config, $batchId);

        $this->sessionState['migration_source'] = $config->getSourceType();
        $this->sessionState['migration_total_migrated'] = $result->getTotalMigrated();
        $this->sessionState['migration_total_conflicts'] = $result->getTotalConflicts();
        $this->sessionState['migration_total_quarantined'] = $result->getTotalQuarantined();
        $this->sessionState['migration_hold_engaged'] = $result->isMigrationHoldEngaged();

        if ($result->isSuccess()) {
            $this->currentStep = InstallationStep::COMPLETED;
        }

        return $result;
    }

    public function setFreshMigrationService(FreshInstallMigrationModeService $service): void
    {
        $this->freshMigrationService = $service;
    }

    public function getFreshMigrationService(): ?FreshInstallMigrationModeService
    {
        return $this->freshMigrationService;
    }

    /**
     * Finalizes installation, writes tamper-evident lock file, and prevents future execution.
     *
     * @param array<string, mixed> $extraMetadata
     */
    public function finalizeInstallation(array $extraMetadata = []): InstallationSummary
    {
        $this->assertNotLocked();

        $appVersion = (string) ($extraMetadata['app_version'] ?? '1.0.0');
        $adminEmail = (string) ($this->sessionState['admin_email'] ?? 'admin@localhost');
        $brandName = (string) ($this->sessionState['brand_name'] ?? 'Coleza Host');
        $driver = (string) ($this->sessionState['db_driver'] ?? 'sqlite');
        $locale = (string) ($this->sessionState['default_locale'] ?? 'en');
        $currency = (string) ($this->sessionState['default_currency'] ?? 'USD');
        $cronCmd = (string) ($this->sessionState['cron_cli_command'] ?? '* * * * * php bin/coleza schedule:run');

        $installedAt = date('c');

        $lockMetadata = array_merge([
            'app_version' => $appVersion,
            'installed_at' => $installedAt,
            'admin_email' => $adminEmail,
            'brand_name' => $brandName,
            'db_driver' => $driver,
            'default_locale' => $locale,
            'default_currency' => $currency,
        ], $extraMetadata);

        // Engage lock
        $this->installerLock->lock($lockMetadata);
        $this->currentStep = InstallationStep::COMPLETED;

        return new InstallationSummary(
            appVersion: $appVersion,
            installedAt: $installedAt,
            adminEmail: $adminEmail,
            brandName: $brandName,
            databaseDriver: $driver,
            defaultLocale: $locale,
            defaultCurrency: $currency,
            cronCliCommand: $cronCmd,
            lockFilePath: $this->installerLock->getLockFilePath(),
            extra: $extraMetadata
        );
    }

    public function getInstallerLock(): InstallerLock
    {
        return $this->installerLock;
    }

    public function getSessionState(): array
    {
        return $this->sessionState;
    }

    private function assertNotLocked(): void
    {
        if ($this->isLocked()) {
            throw InstallationLockedException::alreadyInstalled();
        }
    }

    private function assertDatabaseConnected(): void
    {
        if ($this->connection === null) {
            throw new \RuntimeException('Database is not initialized. Please complete database setup first.');
        }
    }
}
