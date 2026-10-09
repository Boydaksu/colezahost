<?php

declare(strict_types=1);

namespace Tests\Integration\ReleaseRehearsal;

use Coleza\Domain\Backup\BackupDestinationType;
use Coleza\Domain\Backup\BackupScope;
use Coleza\Domain\Backup\EnterpriseBackupService;
use Coleza\Domain\Backup\RestoreWizardService;
use Coleza\Domain\Backup\Storage\LocalBackupStorageAdapter;
use Coleza\Domain\Health\SystemDoctorService;
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
use Coleza\Domain\Installer\InstallerLock;
use Coleza\Domain\Installer\LocaleSetupDto;
use Coleza\Domain\Installer\LocaleSetupService;
use Coleza\Domain\Installer\WebInstallerService;
use Coleza\Domain\Migration\Adoption\AdoptedIdentityRepository;
use Coleza\Domain\Migration\Adoption\DomainAdoptionService;
use Coleza\Domain\Migration\Adoption\ProviderIdentityResolver;
use Coleza\Domain\Migration\Adoption\ServiceAdoptionService;
use Coleza\Domain\Migration\Cutover\CutoverAuditSeal;
use Coleza\Domain\Migration\Cutover\CutoverChecklistReport;
use Coleza\Domain\Migration\Cutover\CutoverChecklistService;
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
use Coleza\Domain\Migration\Whmcs\Financial\WhmcsFinancialReconciliationReport;
use Coleza\Domain\Migration\Whmcs\Support\UnsupportedDataAccountant;
use Coleza\Domain\Migration\Whmcs\Support\WhmcsSupportExtractor;
use Coleza\Domain\Migration\Whmcs\Support\WhmcsSupportMigrator;
use Coleza\Domain\Migration\Whmcs\WhmcsReadOnlyConnector;
use Coleza\Domain\Notifications\NotificationEngine;
use Coleza\Domain\Notifications\Templates\NotificationTemplateEngine;
use Coleza\Domain\Notifications\Transport\MemoryMailTransport;
use Coleza\Domain\Privacy\Tombstone\BackupRestoreReconciliationService;
use Coleza\Domain\Privacy\Tombstone\FileTombstoneStore;
use Coleza\Domain\Privacy\Tombstone\PrivacyTombstoneService;
use Coleza\Domain\Updater\ModuleCompatibilityChecker;
use Coleza\Domain\Updater\PackageSignatureVerifier;
use Coleza\Domain\Updater\StagedUpdateService;
use Coleza\Domain\Updater\UpdatePackageManifest;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * P18.7 Full Release Rehearsal Suite:
 * 1. Fresh installation & installer lock enforcement
 * 2. Cryptographically signed upgrade & staged update
 * 3. Disaster recovery backup, restore & privacy tombstone reconciliation
 * 4. WHMCS cutover rehearsal, financial reconciliation & cryptographic audit seal
 */
final class ReleaseRehearsalLifecycleSuiteTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'coleza_rehearsal_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Rehearsal 1: Fresh Installation Lifecycle & Lock Enforcement.
     */
    public function testFreshInstallationLifecycleAndLockEnforcement(): void
    {
        $lockFile = $this->tempDir . DIRECTORY_SEPARATOR . 'installed.lock';
        $pdo = new PDO('sqlite::memory:');
        $db = new Connection($pdo, 'sqlite');

        $reqChecker = new EnvironmentRequirementChecker();
        $reqChecker->setDirectoryWritableOverride('storage', true);
        $reqChecker->setDirectoryWritableOverride('config', true);
        $reqChecker->setDirectoryWritableOverride('logs', true);

        $installerLock = new InstallerLock($lockFile, $db);
        $installer = new WebInstallerService(
            requirementChecker: $reqChecker,
            databaseSetupService: new DatabaseSetupService(),
            adminBootstrapService: new AdminBootstrapService(),
            localeSetupService: new LocaleSetupService(),
            brandSetupService: new BrandSetupService(),
            emailSetupService: new EmailSetupService(),
            cronSetupService: new CronSetupService(),
            installerLock: $installerLock,
            connection: $db
        );

        $this->assertFalse($installerLock->isLocked());

        // Step 1: Check prerequisites
        $prereqs = $installer->checkRequirements();
        $this->assertTrue($prereqs->isInstallable());

        // Step 2: Database setup
        $dbConfig = new DatabaseConfig('sqlite', 'memory', 0, ':memory:', '', '');
        $dbSetupResult = $installer->setupDatabase($dbConfig);
        $this->assertTrue($dbSetupResult['connection_test']->isSuccess());

        // Step 3: Admin bootstrap
        $adminDto = new AdminSetupDto('admin@colezahost.test', 'P@ssw0rdSecure2026!', 'Sys', 'Admin');
        $adminResult = $installer->setupAdmin($adminDto);
        $this->assertSame('admin@colezahost.test', $adminResult['email']);

        // Step 4: Locale & currencies
        $localeDto = new LocaleSetupDto('en', 'UTC', 'USD', 'Y-m-d');
        $localeResult = $installer->setupLocale($localeDto);
        $this->assertSame('en', $localeResult['app.locale']);

        // Step 5: Brand identity
        $brandDto = new BrandSetupDto('Coleza Cloud Hosting LLC', 'Coleza Cloud Hosting', 'support@colezahost.test', 'colezahost.test');
        $brandResult = $installer->setupBrand($brandDto);
        $this->assertSame('Coleza Cloud Hosting', $brandResult['brand_name']);

        // Step 6: Email configuration
        $emailConfig = new EmailTransportConfig('memory', 'noreply@colezahost.test', 'Coleza Host');
        $emailResult = $installer->setupEmail($emailConfig);
        $this->assertNotEmpty($emailResult['settings']);

        // Step 7: Background scheduler cron
        $cronResult = $installer->setupCron('https://colezahost.test', $this->tempDir);
        $this->assertNotEmpty($cronResult['token']);

        // Finalize installation
        $summary = $installer->finalizeInstallation();
        $this->assertSame('1.0.0', $summary->getAppVersion());
        $this->assertTrue($installerLock->isLocked());
        $this->assertFileExists($lockFile);

        // Verification: Re-running installer must be strictly locked out
        $this->expectException(InstallationLockedException::class);
        $installer->checkRequirements();
    }

    /**
     * Rehearsal 2: Cryptographically Signed Upgrade & Staged Update.
     */
    public function testCryptographicallySignedUpgradeAndStagedUpdate(): void
    {
        $updatePackageDir = $this->tempDir . DIRECTORY_SEPARATOR . 'update_pkg';
        $targetAppDir = $this->tempDir . DIRECTORY_SEPARATOR . 'target_app';
        mkdir($updatePackageDir . '/files', 0755, true);
        mkdir($targetAppDir, 0755, true);

        // Generate cryptographic keypair for release signing (Ed25519)
        $keyPair = PackageSignatureVerifier::generateKeyPair('ed25519');

        // Target app running v1.0.0
        file_put_contents($targetAppDir . '/version.txt', '1.0.0');

        // Create updated file for v1.1.0
        $relFile = 'version.txt';
        $fullPath = $updatePackageDir . '/files/' . $relFile;
        file_put_contents($fullPath, '1.1.0');

        $sha256 = hash_file('sha256', $fullPath);

        // Build signed package manifest
        $manifest = new UpdatePackageManifest(
            version: '1.1.0',
            minCurrentVersion: '1.0.0',
            releaseNotes: 'Coleza Host V1.1.0 patch update with security hardening.',
            fileChecksums: [$relFile => (string)$sha256],
            requiredModules: [],
            migrations: []
        );

        $json = json_encode([
            'version' => $manifest->getVersion(),
            'min_current_version' => $manifest->getMinCurrentVersion(),
            'release_notes' => $manifest->getReleaseNotes(),
            'file_checksums' => $manifest->getFileChecksums(),
            'required_modules' => $manifest->getRequiredModules(),
            'migrations' => $manifest->getMigrations(),
            'released_at' => date('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        file_put_contents($updatePackageDir . '/manifest.json', $json);
        $sig = PackageSignatureVerifier::signPayload((string)$json, $keyPair['private'], 'ed25519');
        file_put_contents($updatePackageDir . '/manifest.sig', $sig);

        // Execute staged update validation & application
        $pdo = new PDO('sqlite::memory:');
        $db = new Connection($pdo, 'sqlite');

        $verifier = new PackageSignatureVerifier($keyPair['public'], 'ed25519');
        $moduleChecker = new ModuleCompatibilityChecker($db);

        $updater = new StagedUpdateService(
            signatureVerifier: $verifier,
            moduleChecker: $moduleChecker,
            currentCoreVersion: '1.0.0',
            db: $db
        );

        $report = $updater->validateStagedPackage($updatePackageDir);
        $this->assertTrue($report->isReadyToApply());
        $this->assertSame('1.1.0', $report->getTargetVersion());

        $applyResult = $updater->applyValidatedUpdate(
            stagedDir: $updatePackageDir,
            targetAppDir: $targetAppDir,
            requireBackup: false
        );

        $this->assertTrue($applyResult['success']);
        $this->assertSame('1.1.0', $applyResult['target_version']);
        $this->assertSame('1.1.0', trim((string) file_get_contents($targetAppDir . '/version.txt')));

        // Verification: Tampered manifest must be rejected
        file_put_contents($updatePackageDir . '/manifest.json', $json . ' ');
        $tamperedReport = $updater->validateStagedPackage($updatePackageDir);
        $this->assertFalse($tamperedReport->isSignatureValid());
        $this->assertFalse($tamperedReport->isReadyToApply());
    }

    /**
     * Rehearsal 3: Disaster Recovery Backup, Restore & Privacy Tombstone Reconciliation.
     */
    public function testDisasterRecoveryBackupRestoreAndPrivacyReconciliation(): void
    {
        $workingDir = $this->tempDir . DIRECTORY_SEPARATOR . 'backup_work';
        $backupDir = $this->tempDir . DIRECTORY_SEPARATOR . 'backups';
        $freshAppDir = $this->tempDir . DIRECTORY_SEPARATOR . 'fresh_app';
        mkdir($workingDir, 0755, true);
        mkdir($backupDir, 0755, true);
        mkdir($freshAppDir, 0755, true);

        // 1. Source Database with user and data
        $srcPdo = new PDO('sqlite::memory:');
        $srcDb = new Connection($srcPdo, 'sqlite');
        $srcDb->statement("CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, name TEXT, status TEXT DEFAULT 'active')");
        $srcDb->statement("INSERT INTO users (id, email, name, status) VALUES (1, 'active@colezahost.test', 'Active User', 'active')");
        $srcDb->statement("INSERT INTO users (id, email, name, status) VALUES (2, 'deleted@colezahost.test', 'Deleted User', 'active')");

        // 2. Take full backup
        $backupAdapter = new LocalBackupStorageAdapter($backupDir);
        $backupService = new EnterpriseBackupService(
            workingDirectory: $workingDir,
            db: $srcDb,
            appVersion: '1.0.0'
        );

        $backupJob = $backupService->createBackup(
            scope: BackupScope::FULL,
            destination: $backupAdapter,
            filePaths: [],
            encrypt: false
        );
        $this->assertArrayHasKey('manifest', $backupJob);
        $manifest = $backupJob['manifest'];
        $backupId = $manifest->getBackupId();
        $this->assertNotEmpty($backupId);

        // 3. User 2 requests GDPR erasure: tombstone is recorded out-of-band in persistent store
        $tombstoneFile = $this->tempDir . DIRECTORY_SEPARATOR . 'tombstones.json';
        $tombstoneStore = new FileTombstoneStore($tombstoneFile);
        $srcTombstoneService = new PrivacyTombstoneService($srcDb, $tombstoneStore);
        $srcTombstoneService->recordTombstone(
            userId: 2,
            email: 'deleted@colezahost.test',
            erasureType: 'ANONYMIZE',
            reason: 'GDPR Article 17 request'
        );
        $this->assertTrue($srcTombstoneService->isTombstoned(2));

        // 4. Catastrophic data loss occurs: fresh empty database is spun up
        $freshPdo = new PDO('sqlite::memory:');
        $freshDb = new Connection($freshPdo, 'sqlite');

        // 5. Restore Wizard restores data and reconciles Privacy Tombstones
        $freshTombstoneService = new PrivacyTombstoneService($freshDb, $tombstoneStore);
        $reconciliationService = new BackupRestoreReconciliationService($freshDb, $freshTombstoneService);

        $restoreWizard = new RestoreWizardService(
            temporaryExtractDir: $this->tempDir . DIRECTORY_SEPARATOR . 'restore_stage',
            reconciliationService: $reconciliationService
        );

        $restoreResult = $restoreWizard->executeRestore(
            backupId: $backupId,
            sourceStorage: $backupAdapter,
            targetDb: $freshDb,
            targetAppDir: $freshAppDir
        );

        $this->assertTrue($restoreResult->isSuccess());

        // 6. Post-Restore Verification:
        // Active user is intact; tombstoned user was re-scrubbed by reconciliation
        $users = $freshDb->select("SELECT id, email, name, status FROM users");
        $emails = array_column($users, 'email');
        $this->assertContains('active@colezahost.test', $emails);
        $this->assertNotContains('deleted@colezahost.test', $emails);

        $scrubbedUser = $freshDb->selectOne("SELECT * FROM users WHERE id = 2");
        $this->assertNotNull($scrubbedUser);
        $this->assertSame('erased', $scrubbedUser['status']);
        $this->assertStringStartsWith('erased_', (string) $scrubbedUser['email']);

        // 7. System Doctor confirms system is fully HEALTHY post-restore
        $freshDb->statement("CREATE TABLE IF NOT EXISTS cron_runs (id INTEGER PRIMARY KEY, ran_at TIMESTAMP, tasks_executed INT)");
        $freshDb->statement("INSERT INTO cron_runs (ran_at, tasks_executed) VALUES (?, 1)", [date('Y-m-d H:i:s')]);
        file_put_contents($backupDir . '/backup_init.manifest.json', '{}');
        file_put_contents($backupDir . '/backup_init.zip', 'content');

        $doctor = new SystemDoctorService(db: $freshDb, storageBasePath: $freshAppDir, backupStorage: $backupAdapter);
        $report = $doctor->diagnoseAll();
        $this->assertTrue($report->isPassing());
    }

    /**
     * Rehearsal 4: Complete WHMCS Cutover Rehearsal & Cryptographic Audit Seal.
     */
    public function testCompleteWhmcsCutoverRehearsalAndAuditSeal(): void
    {
        $batchId = 'batch_rehearsal_cutover_001';

        $whmcsPdo = new PDO('sqlite::memory:');
        $whmcsDb = new Connection($whmcsPdo, 'sqlite');
        $whmcsConnector = new WhmcsReadOnlyConnector($whmcsDb);

        $targetPdo = new PDO('sqlite::memory:');
        $targetDb = new Connection($targetPdo, 'sqlite');

        // Setup source WHMCS schema & seed
        $this->createWhmcsSourceSchema($whmcsDb);
        $this->seedWhmcsSourceDataset($whmcsDb);

        // Setup Target Pipeline Components
        $stagingRepo = new DatabaseStagingRepository($targetDb);
        $mappingEngine = new GenericMappingEngine();
        $validationEngine = new CanonicalValidationEngine();
        $stagingPipeline = new StagingPipelineService($stagingRepo, $mappingEngine, $validationEngine);

        $identityResolver = new ProviderIdentityResolver();
        $adoptedRepo = new AdoptedIdentityRepository($targetDb);
        $serviceAdopter = new ServiceAdoptionService($targetDb, $adoptedRepo, $identityResolver);
        $domainAdopter = new DomainAdoptionService($targetDb, $adoptedRepo, $identityResolver);

        $coreExtractor = new WhmcsCoreEntityExtractor($whmcsConnector);
        $financialExtractor = new WhmcsFinancialExtractor($whmcsConnector);
        $supportExtractor = new WhmcsSupportExtractor($whmcsConnector);

        $coreMigrator = new WhmcsCoreEntityMigrator(
            targetDb: $targetDb,
            whmcs: $whmcsConnector,
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
            targetDb: $targetDb,
            whmcs: $whmcsConnector,
            stagingRepo: $stagingRepo,
            stagingPipeline: $stagingPipeline,
            identityResolver: $identityResolver,
            extractor: $financialExtractor
        );

        $supportMigrator = new WhmcsSupportMigrator(
            targetDb: $targetDb,
            whmcs: $whmcsConnector,
            stagingRepo: $stagingRepo,
            stagingPipeline: $stagingPipeline,
            identityResolver: $identityResolver,
            extractor: $supportExtractor
        );

        $unsupportedAccountant = new UnsupportedDataAccountant($whmcsConnector, $stagingRepo);
        $holdRepo = new MigrationHoldRepository($targetDb);
        $holdService = new MigrationHoldService($holdRepo);

        $suppressionRepo = new SuppressedNotificationRepository($targetDb);
        $mailTransport = new MemoryMailTransport();
        $templateEngine = new NotificationTemplateEngine();
        $notificationEngine = new NotificationEngine($mailTransport, $templateEngine);

        $suppressionManager = new NotificationSuppressionManager(
            holdService: $holdService,
            repository: $suppressionRepo,
            engine: $notificationEngine
        );

        $checkpointRepo = new MigrationCheckpointRepository($targetDb);
        $conflictDetector = new ConflictDetector($targetDb);
        $quarantineManager = new QuarantineManager($stagingRepo, $stagingPipeline);

        $orchestrator = new MigrationExecutionOrchestrator(
            targetDb: $targetDb,
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
            holdService: $holdService,
            suppressionManager: $suppressionManager
        );

        $cutoverService = new CutoverChecklistService(
            stagingRepo: $stagingRepo,
            financialMigrator: $financialMigrator,
            holdService: $holdService,
            whmcsConnector: $whmcsConnector,
            checkpointRepo: $checkpointRepo,
            unsupportedDataAccountant: $unsupportedAccountant
        );

        // 1. Dry Run Verification
        $dryRun = $orchestrator->executeDryRun($batchId);
        $this->assertTrue($dryRun->isReadyForLiveMigration());
        $this->assertSame(0, $dryRun->getTotalProjectedQuarantined());

        // 2. Live Migration Execution with Safety Hold
        $liveResult = $orchestrator->executeLive($batchId, [
            'apply_migration_hold' => true,
        ]);
        $this->assertSame('completed', $liveResult['status']);
        $this->assertTrue($liveResult['zero_silent_loss_achieved']);
        $this->assertTrue($liveResult['fully_terminal']);

        // 3. Multi-currency Financial Ledger Zero-Diff Reconciliation
        $financialReport = $financialMigrator->migrateAndReconcile($batchId);
        $this->assertTrue($financialReport->isFullyReconciled());
        $this->assertTrue($financialReport->isZeroSilentLossAchieved());
        $this->assertTrue($financialReport->isCertified());

        // 4. Evaluate Comprehensive 6-Gate Cutover Checklist
        $checklist = $cutoverService->evaluateChecklist($batchId, $financialReport);
        $this->assertInstanceOf(CutoverChecklistReport::class, $checklist);
        $this->assertTrue($checklist->isReadyForCutover());
        $this->assertSame(0, $checklist->getFailedCount());
        $this->assertGreaterThanOrEqual(6, $checklist->getPassedCount());

        $this->assertTrue($checklist->getItem('source_read_only')->isPassed());
        $this->assertTrue($checklist->getItem('staging_terminal_status')->isPassed());
        $this->assertTrue($checklist->getItem('financial_reconciliation')->isPassed());
        $this->assertTrue($checklist->getItem('migration_safety_hold')->isPassed());
        $this->assertTrue($checklist->getItem('execution_checkpoint')->isPassed());

        // 5. Seal Cutover with Cryptographic Audit Integrity
        $seal = $cutoverService->sealCutover(
            batchId: $batchId,
            approvedBy: 'release-manager@coleza.internal',
            financialReport: $financialReport,
            signature: 'ED25519-SIG-COLEZA-REHEARSAL-P18-7'
        );

        $this->assertInstanceOf(CutoverAuditSeal::class, $seal);
        $this->assertNotEmpty($seal->getSha256Checksum());
        $this->assertTrue($seal->verifyChecksum());
        $this->assertSame('SEALED', $seal->getStatus());
        $this->assertSame('release-manager@coleza.internal', $seal->getApprovedBy());
    }

    private function createWhmcsSourceSchema(Connection $db): void
    {
        $db->statement('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, firstname TEXT, lastname TEXT, companyname TEXT, email TEXT, address1 TEXT, address2 TEXT, city TEXT, state TEXT, postcode TEXT, country TEXT, phonenumber TEXT, currency INT, status TEXT, datecreated TEXT, credit REAL DEFAULT 0.0)');
        $db->statement('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT, prefix TEXT, suffix TEXT, format INT, rate REAL)');
        $db->statement('CREATE TABLE tblproducts (id INTEGER PRIMARY KEY, gid INT, type TEXT, name TEXT, description TEXT, paytype TEXT, servertype TEXT, autosetup TEXT)');
        $db->statement('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, orderid INT, packageid INT, server INT, regdate TEXT, domain TEXT, paymentmethod TEXT, firstpaymentamount REAL, amount REAL, billingcycle TEXT, nextduedate TEXT, domainstatus TEXT, username TEXT, password TEXT, dedicatedip TEXT, assignedips TEXT, diskusage REAL, disklimit REAL, bwusage REAL, bwlimit REAL, lastupdate TEXT)');
        $db->statement('CREATE TABLE tbldomains (id INTEGER PRIMARY KEY, userid INT, orderid INT, type TEXT, registrationdate TEXT, domain TEXT, firstpaymentamount REAL, recurringamount REAL, registrar TEXT, registrationperiod INT, expirydate TEXT, nextduedate TEXT, status TEXT, subscriptionid TEXT, dnsmanagement INT, emailforwarding INT, idprotection INT, donotrenew INT)');
        $db->statement('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum TEXT, date TEXT, duedate TEXT, datepaid TEXT, subtotal REAL, credit REAL, tax REAL, tax2 REAL, total REAL, taxrate REAL, taxrate2 REAL, status TEXT, paymentmethod TEXT, notes TEXT)');
        $db->statement('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, description TEXT, amount REAL, taxed INT, duedate TEXT, paymentmethod TEXT, notes TEXT)');
        $db->statement('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, currency INT, gateway TEXT, date TEXT, description TEXT, amountin REAL, fees REAL, amountout REAL, transid TEXT, invoiceid INT, refundid INT)');
        $db->statement('CREATE TABLE tblcredit (id INTEGER PRIMARY KEY, clientid INT, date TEXT, description TEXT, amount REAL, relid INT)');
        $db->statement('CREATE TABLE tblticketdepartments (id INTEGER PRIMARY KEY, name TEXT, description TEXT, email TEXT, hidden INT, order_num INT)');
        $db->statement('CREATE TABLE tbltickets (id INTEGER PRIMARY KEY, did INT, userid INT, contactid INT, name TEXT, email TEXT, date TEXT, title TEXT, message TEXT, status TEXT, urgency TEXT, admin TEXT, attachment TEXT, lastreply TEXT, flag INT, clientunread INT, adminunread INT)');
        $db->statement('CREATE TABLE tblticketreplies (id INTEGER PRIMARY KEY, tid INT, userid INT, contactid INT, name TEXT, email TEXT, date TEXT, message TEXT, admin TEXT, attachment TEXT, rating INT)');
        $db->statement('CREATE TABLE tblcustomfields (id INTEGER PRIMARY KEY, type TEXT, relid INT, fieldname TEXT, fieldtype TEXT, description TEXT, fieldoptions TEXT, regexpr TEXT, adminonly TEXT, required TEXT, showorder TEXT, showinvoice TEXT, sortorder INT)');
        $db->statement('CREATE TABLE tblcustomfieldsvalues (id INTEGER PRIMARY KEY, fieldid INT, relid INT, value TEXT)');
    }

    private function seedWhmcsSourceDataset(Connection $db): void
    {
        $db->statement("INSERT INTO tblcurrencies VALUES (1, 'USD', '$', '', 1, 1.0)");
        $db->statement("INSERT INTO tblcurrencies VALUES (2, 'TRY', '₺', '', 1, 34.5)");
        $db->statement("INSERT INTO tblclients VALUES (1, 'Alice', 'Tester', 'Alice Tech', 'alice@test.org', '123 Main St', '', 'London', '', 'EC1A', 'GB', '+442079460991', 1, 'Active', '2024-01-01', 0.0)");
        $db->statement("INSERT INTO tblproducts VALUES (1, 1, 'hostingaccount', 'Starter Hosting', 'Entry hosting plan', 'recurring', 'cpanel', 'order')");
        $db->statement("INSERT INTO tblhosting VALUES (10, 1, 1, 1, 1, '2024-01-01', 'aliceweb.com', 'stripe', 10.0, 10.0, 'Monthly', '2024-02-01', 'Active', 'alicew', 'pass123', '', '', 100, 5000, 50, 10000, '2024-01-01')");
        $db->statement("INSERT INTO tbldomains VALUES (20, 1, 1, 'Register', '2024-01-01', 'aliceweb.com', 12.0, 12.0, 'enom', 1, '2025-01-01', '2025-01-01', 'Active', '', 1, 1, 0, 0)");
        $db->statement("INSERT INTO tblinvoices VALUES (30, 1, 'INV-2024-001', '2024-01-01', '2024-01-15', '2024-01-02', 22.0, 0.0, 0.0, 0.0, 22.0, 0.0, 0.0, 'Paid', 'stripe', '')");
        $db->statement("INSERT INTO tblinvoiceitems VALUES (1, 30, 1, 'Hosting', 10, 'Starter Hosting - aliceweb.com', 10.0, 0, '2024-01-01', 'stripe', '')");
        $db->statement("INSERT INTO tblinvoiceitems VALUES (2, 30, 1, 'DomainRegister', 20, 'Domain Registration - aliceweb.com', 12.0, 0, '2024-01-01', 'stripe', '')");
        $db->statement("INSERT INTO tblaccounts VALUES (1, 1, 1, 'stripe', '2024-01-02', 'Payment for INV-2024-001', 22.0, 0.75, 0.0, 'txn_rehearsal_001', 30, 0)");
    }
}
