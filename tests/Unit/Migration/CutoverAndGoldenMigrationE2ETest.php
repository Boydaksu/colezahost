<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Migration;

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
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class CutoverAndGoldenMigrationE2ETest extends TestCase
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

    private WhmcsCoreEntityExtractor $coreExtractor;
    private WhmcsFinancialExtractor $financialExtractor;
    private WhmcsSupportExtractor $supportExtractor;

    private WhmcsCoreEntityMigrator $coreMigrator;
    private WhmcsFinancialMigrator $financialMigrator;
    private WhmcsSupportMigrator $supportMigrator;
    private UnsupportedDataAccountant $unsupportedAccountant;

    private MigrationHoldRepository $holdRepo;
    private MigrationHoldService $holdService;
    private SuppressedNotificationRepository $suppressionRepo;
    private NotificationSuppressionManager $suppressionManager;
    private MemoryMailTransport $mailTransport;
    private NotificationEngine $notificationEngine;

    private MigrationCheckpointRepository $checkpointRepo;
    private ConflictDetector $conflictDetector;
    private QuarantineManager $quarantineManager;
    private MigrationExecutionOrchestrator $orchestrator;
    private CutoverChecklistService $cutoverService;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Initialize SQLite connections
        $whmcsPdo = new PDO('sqlite::memory:');
        $this->whmcsDb = new Connection($whmcsPdo, 'sqlite');
        $this->whmcsConnector = new WhmcsReadOnlyConnector($this->whmcsDb);

        $targetPdo = new PDO('sqlite::memory:');
        $this->targetDb = new Connection($targetPdo, 'sqlite');

        // 2. Setup WHMCS Golden Schema
        $this->createWhmcsGoldenSchema();
        $this->seedWhmcsGoldenDataset();

        // 3. Setup Target Infrastructure & Pipeline
        $this->stagingRepo = new DatabaseStagingRepository($this->targetDb);
        $this->mappingEngine = new GenericMappingEngine();
        $this->validationEngine = new CanonicalValidationEngine();
        $this->stagingPipeline = new StagingPipelineService($this->stagingRepo, $this->mappingEngine, $this->validationEngine);

        $this->identityResolver = new ProviderIdentityResolver();
        $this->adoptedRepo = new AdoptedIdentityRepository($this->targetDb);
        $this->serviceAdopter = new ServiceAdoptionService($this->targetDb, $this->adoptedRepo, $this->identityResolver);
        $this->domainAdopter = new DomainAdoptionService($this->targetDb, $this->adoptedRepo, $this->identityResolver);

        $this->coreExtractor = new WhmcsCoreEntityExtractor($this->whmcsConnector);
        $this->financialExtractor = new WhmcsFinancialExtractor($this->whmcsConnector);
        $this->supportExtractor = new WhmcsSupportExtractor($this->whmcsConnector);

        $this->coreMigrator = new WhmcsCoreEntityMigrator(
            targetDb: $this->targetDb,
            whmcs: $this->whmcsConnector,
            stagingRepo: $this->stagingRepo,
            stagingPipeline: $this->stagingPipeline,
            mappingEngine: $this->mappingEngine,
            identityResolver: $this->identityResolver,
            serviceAdoptionService: $this->serviceAdopter,
            domainAdoptionService: $this->domainAdopter,
            adoptedIdentityRepo: $this->adoptedRepo,
            extractor: $this->coreExtractor
        );

        $this->financialMigrator = new WhmcsFinancialMigrator(
            targetDb: $this->targetDb,
            whmcs: $this->whmcsConnector,
            stagingRepo: $this->stagingRepo,
            stagingPipeline: $this->stagingPipeline,
            identityResolver: $this->identityResolver,
            extractor: $this->financialExtractor
        );

        $this->supportMigrator = new WhmcsSupportMigrator(
            targetDb: $this->targetDb,
            whmcs: $this->whmcsConnector,
            stagingRepo: $this->stagingRepo,
            stagingPipeline: $this->stagingPipeline,
            identityResolver: $this->identityResolver,
            extractor: $this->supportExtractor
        );

        $this->unsupportedAccountant = new UnsupportedDataAccountant($this->whmcsConnector, $this->stagingRepo);

        $this->holdRepo = new MigrationHoldRepository($this->targetDb);
        $this->holdService = new MigrationHoldService($this->holdRepo);

        $this->suppressionRepo = new SuppressedNotificationRepository($this->targetDb);
        $this->mailTransport = new MemoryMailTransport();
        $templateEngine = new NotificationTemplateEngine();
        $this->notificationEngine = new NotificationEngine($this->mailTransport, $templateEngine);

        $this->suppressionManager = new NotificationSuppressionManager(
            holdService: $this->holdService,
            repository: $this->suppressionRepo,
            engine: $this->notificationEngine
        );

        $this->checkpointRepo = new MigrationCheckpointRepository($this->targetDb);
        $this->conflictDetector = new ConflictDetector($this->targetDb);
        $this->quarantineManager = new QuarantineManager($this->stagingRepo, $this->stagingPipeline);

        $this->orchestrator = new MigrationExecutionOrchestrator(
            targetDb: $this->targetDb,
            coreMigrator: $this->coreMigrator,
            financialMigrator: $this->financialMigrator,
            supportMigrator: $this->supportMigrator,
            conflictDetector: $this->conflictDetector,
            checkpointRepo: $this->checkpointRepo,
            quarantineManager: $this->quarantineManager,
            stagingRepo: $this->stagingRepo,
            validationEngine: $this->validationEngine,
            coreExtractor: $this->coreExtractor,
            financialExtractor: $this->financialExtractor,
            supportExtractor: $this->supportExtractor,
            holdService: $this->holdService,
            suppressionManager: $this->suppressionManager
        );

        $this->cutoverService = new CutoverChecklistService(
            stagingRepo: $this->stagingRepo,
            financialMigrator: $this->financialMigrator,
            holdService: $this->holdService,
            whmcsConnector: $this->whmcsConnector,
            checkpointRepo: $this->checkpointRepo,
            unsupportedDataAccountant: $this->unsupportedAccountant
        );
    }

    private function createWhmcsGoldenSchema(): void
    {
        $this->whmcsDb->statement('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, firstname TEXT, lastname TEXT, companyname TEXT, email TEXT, address1 TEXT, address2 TEXT, city TEXT, state TEXT, postcode TEXT, country TEXT, phonenumber TEXT, currency INT, status TEXT, datecreated TEXT, credit REAL DEFAULT 0.0)');
        $this->whmcsDb->statement('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT, prefix TEXT, suffix TEXT, format INT, rate REAL)');
        $this->whmcsDb->statement('CREATE TABLE tblproducts (id INTEGER PRIMARY KEY, gid INT, type TEXT, name TEXT, description TEXT, paytype TEXT, servertype TEXT, autosetup TEXT)');
        $this->whmcsDb->statement('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, orderid INT, packageid INT, server INT, regdate TEXT, domain TEXT, paymentmethod TEXT, firstpaymentamount REAL, amount REAL, billingcycle TEXT, nextduedate TEXT, domainstatus TEXT, username TEXT, password TEXT, dedicatedip TEXT, assignedips TEXT, diskusage REAL, disklimit REAL, bwusage REAL, bwlimit REAL, lastupdate TEXT)');
        $this->whmcsDb->statement('CREATE TABLE tbldomains (id INTEGER PRIMARY KEY, userid INT, orderid INT, type TEXT, registrationdate TEXT, domain TEXT, firstpaymentamount REAL, recurringamount REAL, registrar TEXT, registrationperiod INT, expirydate TEXT, nextduedate TEXT, status TEXT, subscriptionid TEXT, dnsmanagement INT, emailforwarding INT, idprotection INT, donotrenew INT)');
        $this->whmcsDb->statement('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum TEXT, date TEXT, duedate TEXT, datepaid TEXT, subtotal REAL, credit REAL, tax REAL, tax2 REAL, total REAL, taxrate REAL, taxrate2 REAL, status TEXT, paymentmethod TEXT, notes TEXT)');
        $this->whmcsDb->statement('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, description TEXT, amount REAL, taxed INT, duedate TEXT, paymentmethod TEXT, notes TEXT)');
        $this->whmcsDb->statement('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, currency INT, gateway TEXT, date TEXT, description TEXT, amountin REAL, fees REAL, amountout REAL, transid TEXT, invoiceid INT, refundid INT)');
        $this->whmcsDb->statement('CREATE TABLE tblcredit (id INTEGER PRIMARY KEY, clientid INT, date TEXT, description TEXT, amount REAL, relid INT)');
        $this->whmcsDb->statement('CREATE TABLE tblticketdepartments (id INTEGER PRIMARY KEY, name TEXT, description TEXT, email TEXT, hidden INT, order_num INT)');
        $this->whmcsDb->statement('CREATE TABLE tbltickets (id INTEGER PRIMARY KEY, did INT, userid INT, contactid INT, name TEXT, email TEXT, date TEXT, title TEXT, message TEXT, status TEXT, urgency TEXT, admin TEXT, attachment TEXT, lastreply TEXT, flag INT, clientunread INT, adminunread INT)');
        $this->whmcsDb->statement('CREATE TABLE tblticketreplies (id INTEGER PRIMARY KEY, tid INT, userid INT, contactid INT, name TEXT, email TEXT, date TEXT, message TEXT, admin TEXT, attachment TEXT, rating INT)');
        $this->whmcsDb->statement('CREATE TABLE tblcustomfields (id INTEGER PRIMARY KEY, type TEXT, relid INT, fieldname TEXT, fieldtype TEXT, description TEXT, fieldoptions TEXT, regexpr TEXT, adminonly TEXT, required TEXT, showorder TEXT, showinvoice TEXT, sortorder INT)');
        $this->whmcsDb->statement('CREATE TABLE tblcustomfieldsvalues (id INTEGER PRIMARY KEY, fieldid INT, relid INT, value TEXT)');
    }

    private function seedWhmcsGoldenDataset(): void
    {
        // Currencies
        $this->whmcsDb->statement("INSERT INTO tblcurrencies VALUES (1, 'USD', '$', '', 1, 1.0)");
        $this->whmcsDb->statement("INSERT INTO tblcurrencies VALUES (2, 'TRY', '₺', '', 1, 34.5)");
        $this->whmcsDb->statement("INSERT INTO tblcurrencies VALUES (3, 'EUR', '€', '', 1, 0.92)");

        // Clients
        $this->whmcsDb->statement("INSERT INTO tblclients VALUES (1, 'John', 'Smith', 'Smith Corp', 'john@smithcorp.com', '100 Broadway', '', 'New York', 'NY', '10001', 'US', '+1-555-0100', 1, 'Active', '2023-01-01', 50.0)");
        $this->whmcsDb->statement("INSERT INTO tblclients VALUES (2, 'Ahmet', 'Yilmaz', 'Yilmaz Yazilim', 'ahmet@yilmazyazilim.com', 'Levent Mah.', '', 'Istanbul', 'TR', '34330', 'TR', '+90-532-0000', 2, 'Active', '2023-02-01', 0.0)");
        $this->whmcsDb->statement("INSERT INTO tblclients VALUES (3, 'Marie', 'Dubois', 'Dubois SARL', 'marie@dubois.fr', 'Rue de Rivoli', '', 'Paris', 'IDF', '75001', 'FR', '+33-1-0000', 3, 'Active', '2023-03-01', 0.0)");

        // Products
        $this->whmcsDb->statement("INSERT INTO tblproducts VALUES (1, 1, 'hostingaccount', 'Business Hosting', 'cPanel Pro Plan', 'recurring', 'cpanel', 'order')");
        $this->whmcsDb->statement("INSERT INTO tblproducts VALUES (2, 1, 'server', 'Cloud VPS 2C4G', 'High performance VPS', 'recurring', 'proxmox', 'order')");

        // Services (Hosting accounts)
        $this->whmcsDb->statement("INSERT INTO tblhosting VALUES (10, 1, 1, 1, 1, '2023-01-10', 'smithcorp.com', 'stripe', 20.0, 20.0, 'Monthly', '2023-02-10', 'Active', 'smithc', 'secret123', '', '', 500, 10000, 200, 50000, '2023-01-10')");
        $this->whmcsDb->statement("INSERT INTO tblhosting VALUES (11, 2, 2, 1, 1, '2023-02-15', 'yilmazyazilim.com', 'paytr', 500.0, 500.0, 'Monthly', '2023-03-15', 'Active', 'yilmazc', 'secret456', '', '', 250, 10000, 100, 50000, '2023-02-15')");
        $this->whmcsDb->statement("INSERT INTO tblhosting VALUES (12, 3, 3, 2, 2, '2023-03-20', 'dubois-vps.fr', 'stripe', 50.0, 50.0, 'Monthly', '2023-04-20', 'Active', 'duboisvps', 'secret789', '198.51.100.25', '', 0, 0, 0, 0, '2023-03-20')");

        // Domains
        $this->whmcsDb->statement("INSERT INTO tbldomains VALUES (20, 1, 1, 'Register', '2023-01-10', 'smithcorp.com', 15.0, 15.0, 'enom', 1, '2024-01-10', '2024-01-10', 'Active', '', 1, 1, 0, 0)");
        $this->whmcsDb->statement("INSERT INTO tbldomains VALUES (21, 2, 2, 'Register', '2023-02-15', 'yilmazyazilim.com', 350.0, 350.0, 'metaregistrar', 1, '2024-02-15', '2024-02-15', 'Active', '', 1, 0, 1, 0)");

        // Invoices
        $this->whmcsDb->statement("INSERT INTO tblinvoices VALUES (30, 1, 'INV-USD-1001', '2023-01-10', '2023-01-20', '2023-01-11', 35.0, 0.0, 0.0, 0.0, 35.0, 0.0, 0.0, 'Paid', 'stripe', '')");
        $this->whmcsDb->statement("INSERT INTO tblinvoices VALUES (31, 2, 'INV-TRY-2001', '2023-02-15', '2023-02-25', '2023-02-16', 850.0, 0.0, 0.0, 0.0, 850.0, 0.0, 0.0, 'Paid', 'paytr', '')");
        $this->whmcsDb->statement("INSERT INTO tblinvoices VALUES (32, 3, 'INV-EUR-3001', '2023-03-20', '2023-03-30', '2023-03-21', 50.0, 0.0, 0.0, 0.0, 50.0, 0.0, 0.0, 'Paid', 'stripe', '')");

        // Invoice Items
        $this->whmcsDb->statement("INSERT INTO tblinvoiceitems VALUES (1, 30, 1, 'Hosting', 10, 'Business Hosting - smithcorp.com', 20.0, 0, '2023-01-10', 'stripe', '')");
        $this->whmcsDb->statement("INSERT INTO tblinvoiceitems VALUES (2, 30, 1, 'DomainRegister', 20, 'Domain Registration - smithcorp.com', 15.0, 0, '2023-01-10', 'stripe', '')");
        $this->whmcsDb->statement("INSERT INTO tblinvoiceitems VALUES (3, 31, 2, 'Hosting', 11, 'Business Hosting - yilmazyazilim.com', 500.0, 0, '2023-02-15', 'paytr', '')");
        $this->whmcsDb->statement("INSERT INTO tblinvoiceitems VALUES (4, 31, 2, 'DomainRegister', 21, 'Domain Registration - yilmazyazilim.com', 350.0, 0, '2023-02-15', 'paytr', '')");
        $this->whmcsDb->statement("INSERT INTO tblinvoiceitems VALUES (5, 32, 3, 'Hosting', 12, 'Cloud VPS - dubois-vps.fr', 50.0, 0, '2023-03-20', 'stripe', '')");

        // Payments
        $this->whmcsDb->statement("INSERT INTO tblaccounts VALUES (1, 1, 1, 'stripe', '2023-01-11', 'Payment for INV-USD-1001', 35.0, 1.20, 0.0, 'txn_usd_001', 30, 0)");
        $this->whmcsDb->statement("INSERT INTO tblaccounts VALUES (2, 2, 2, 'paytr', '2023-02-16', 'Payment for INV-TRY-2001', 850.0, 25.5, 0.0, 'txn_try_002', 31, 0)");
        $this->whmcsDb->statement("INSERT INTO tblaccounts VALUES (3, 3, 3, 'stripe', '2023-03-21', 'Payment for INV-EUR-3001', 50.0, 1.75, 0.0, 'txn_eur_003', 32, 0)");

        // Client Credits
        $this->whmcsDb->statement("INSERT INTO tblcredit VALUES (1, 1, '2023-01-01', 'Overpayment credit balance', 50.0, 0)");

        // Support Department & Tickets
        $this->whmcsDb->statement("INSERT INTO tblticketdepartments VALUES (1, 'Technical Support', 'Infrastructure and hosting help', 'support@coleza.com', 0, 1)");
        $this->whmcsDb->statement("INSERT INTO tbltickets VALUES (1, 1, 1, 0, 'John Smith', 'john@smithcorp.com', '2023-01-12 10:00:00', 'PHP Configuration Inquiry', 'Could you enable the intl extension?', 'Answered', 'Medium', 'Staff', '', '2023-01-12 10:30:00', 0, 0, 0)");
        $this->whmcsDb->statement("INSERT INTO tblticketreplies VALUES (1, 1, 0, 0, 'Support Agent', 'support@coleza.com', '2023-01-12 10:30:00', 'PHP intl extension is enabled by default.', 'Staff', '', 5)");

        // Custom Fields
        $this->whmcsDb->statement("INSERT INTO tblcustomfields VALUES (1, 'client', 0, 'Tax Identification Number', 'text', 'VAT/Tax ID', '', '', 'off', 'off', 'on', 'on', 1)");
        $this->whmcsDb->statement("INSERT INTO tblcustomfieldsvalues VALUES (1, 1, 1, 'US-987654321')");
        $this->whmcsDb->statement("INSERT INTO tblcustomfieldsvalues VALUES (2, 1, 2, 'TR-1234567890')");
    }

    public function testGoldenMigrationEndToEndWithCutoverChecklistAndAuditSeal(): void
    {
        $batchId = 'batch_golden_v1_001';

        // 1. Dry-Run Verification
        $dryRun = $this->orchestrator->executeDryRun($batchId);
        $this->assertSame($batchId, $dryRun->getBatchId());
        $this->assertGreaterThan(0, $dryRun->getTotalInspected());
        $this->assertSame(0, $dryRun->getTotalProjectedQuarantined());
        $this->assertTrue($dryRun->isReadyForLiveMigration());

        // 2. Live Migration Execution
        $liveResult = $this->orchestrator->executeLive($batchId, [
            'apply_migration_hold' => true,
        ]);

        $this->assertSame('completed', $liveResult['status']);
        $this->assertTrue($liveResult['zero_silent_loss_achieved']);
        $this->assertTrue($liveResult['fully_terminal']);

        // Checkpoint marked completed
        $checkpoint = $this->checkpointRepo->find($batchId);
        $this->assertNotNull($checkpoint);
        $this->assertTrue($checkpoint->isCompleted());

        // 3. Verify Financial Zero-Diff Reconciliation
        $financialReport = $this->financialMigrator->migrateAndReconcile($batchId);
        $this->assertTrue($financialReport->isFullyReconciled());

        $invDiff = $financialReport->getInvoiceReconciliation()['diff'];
        foreach ($invDiff as $currency => $diff) {
            $this->assertSame(0.0, (float) $diff, "Invoice diff for {$currency} must be 0.00");
        }

        $payDiff = $financialReport->getPaymentReconciliation()['diff'];
        foreach ($payDiff as $currency => $diff) {
            $this->assertSame(0.0, (float) $diff, "Payment diff for {$currency} must be 0.00");
        }

        // 4. Verify Migration Hold & Notification Suppression
        $this->assertNotNull($liveResult['migration_hold_summary']);
        $holdSummary = $liveResult['migration_hold_summary'];
        $this->assertGreaterThan(0, $holdSummary['active']);

        // Test that automation is suppressed for migrated hosting accounts
        $serviceId = (int) $liveResult['steps']['services']['service_ids']['10'];
        $this->assertTrue($this->holdService->isAutomationSuppressed('service', $serviceId, 'suspend'));
        $this->assertTrue($this->holdService->isAutomationSuppressed('service', $serviceId, 'terminate'));

        // Test that outbound customer notifications are intercepted and suppressed
        $clientId = (int) $liveResult['steps']['clients']['user_ids']['1'];
        $suppressionResult = $this->suppressionManager->dispatchOrSuppress(
            batchId: $batchId,
            templateKey: 'invoice_created',
            data: [
                'customer_name' => 'John Smith',
                'invoice_number' => 'INV-USD-1001',
                'total_formatted' => '$35.00',
                'due_date' => '2023-01-20',
            ],
            recipientEmail: 'john@smithcorp.com',
            entityType: 'client',
            entityId: $clientId
        );

        $this->assertFalse($suppressionResult->isSuccess());
        $this->assertSame('suppression_intercept', $suppressionResult->getTransport());
        $this->assertCount(0, $this->mailTransport->getSentMessages());

        // 5. Evaluate Cutover Checklist
        $checklist = $this->cutoverService->evaluateChecklist($batchId, $financialReport);
        $this->assertInstanceOf(CutoverChecklistReport::class, $checklist);
        $this->assertTrue($checklist->isReadyForCutover());
        $this->assertSame(0, $checklist->getFailedCount());
        $this->assertGreaterThanOrEqual(6, $checklist->getPassedCount());

        $this->assertTrue($checklist->getItem('source_read_only')->isPassed());
        $this->assertTrue($checklist->getItem('staging_terminal_status')->isPassed());
        $this->assertTrue($checklist->getItem('financial_reconciliation')->isPassed());
        $this->assertTrue($checklist->getItem('migration_safety_hold')->isPassed());
        $this->assertTrue($checklist->getItem('execution_checkpoint')->isPassed());

        // 6. Seal Cutover & Certify Audit Integrity
        $seal = $this->cutoverService->sealCutover(
            batchId: $batchId,
            approvedBy: 'lead-architect@coleza.internal',
            financialReport: $financialReport,
            signature: 'ED25519-SIG-COLEZA-CUTOVER-PASS'
        );

        $this->assertInstanceOf(CutoverAuditSeal::class, $seal);
        $this->assertSame($batchId, $seal->getBatchId());
        $this->assertSame('lead-architect@coleza.internal', $seal->getApprovedBy());
        $this->assertSame('SEALED', $seal->getStatus());
        $this->assertNotEmpty($seal->getSha256Checksum());
        $this->assertTrue($seal->verifyChecksum());

        // Verify seal serialization contains valid signature and checksum
        $sealArray = $seal->toArray();
        $this->assertTrue($sealArray['checksum_valid']);
        $this->assertSame('ED25519-SIG-COLEZA-CUTOVER-PASS', $sealArray['signature']);
    }

    public function testCutoverSealFailsWhenChecklistPrerequisitesViolated(): void
    {
        $batchId = 'batch_failing_prereq_002';

        // Stage an incomplete record that remains in flight (STAGED status, not terminal)
        $this->stagingPipeline->stageRawRecord(
            batchId: $batchId,
            sourceSystem: 'whmcs',
            sourceEntityType: 'service',
            sourceEntityId: '999',
            rawPayload: ['domain' => 'unaccounted.com']
        );

        // Checklist should catch in-flight records and fail staging_terminal_status
        $checklist = $this->cutoverService->evaluateChecklist($batchId);
        $this->assertFalse($checklist->isReadyForCutover());
        $this->assertFalse($checklist->getItem('staging_terminal_status')->isPassed());

        // Attempting to seal must throw RuntimeException
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot seal cutover for batch [batch_failing_prereq_002]');
        $this->cutoverService->sealCutover($batchId, 'lead-architect');
    }
}
