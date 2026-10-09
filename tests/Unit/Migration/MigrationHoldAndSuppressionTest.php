<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Migration;

use Coleza\Domain\Migration\Adoption\AdoptedIdentityRepository;
use Coleza\Domain\Migration\Adoption\DomainAdoptionService;
use Coleza\Domain\Migration\Adoption\ProviderIdentityResolver;
use Coleza\Domain\Migration\Adoption\ServiceAdoptionService;
use Coleza\Domain\Migration\Execution\ConflictDetector;
use Coleza\Domain\Migration\Execution\MigrationCheckpointRepository;
use Coleza\Domain\Migration\Execution\MigrationExecutionOrchestrator;
use Coleza\Domain\Migration\Execution\QuarantineManager;
use Coleza\Domain\Migration\Hold\MigrationHoldRecord;
use Coleza\Domain\Migration\Hold\MigrationHoldRepository;
use Coleza\Domain\Migration\Hold\MigrationHoldService;
use Coleza\Domain\Migration\Hold\MigrationHoldStatus;
use Coleza\Domain\Migration\Hold\NotificationSuppressionManager;
use Coleza\Domain\Migration\Hold\SuppressedNotification;
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

final class MigrationHoldAndSuppressionTest extends TestCase
{
    private Connection $db;
    private MigrationHoldRepository $holdRepo;
    private MigrationHoldService $holdService;
    private SuppressedNotificationRepository $suppressionRepo;
    private NotificationSuppressionManager $suppressionManager;
    private MemoryMailTransport $mailTransport;
    private NotificationEngine $notificationEngine;

    protected function setUp(): void
    {
        parent::setUp();

        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->holdRepo = new MigrationHoldRepository($this->db);
        $this->holdService = new MigrationHoldService($this->holdRepo);

        $this->suppressionRepo = new SuppressedNotificationRepository($this->db);

        $this->mailTransport = new MemoryMailTransport();
        $templateEngine = new NotificationTemplateEngine();
        $this->notificationEngine = new NotificationEngine($this->mailTransport, $templateEngine);

        $this->suppressionManager = new NotificationSuppressionManager(
            holdService: $this->holdService,
            repository: $this->suppressionRepo,
            engine: $this->notificationEngine
        );
    }

    public function testGlobalHoldActivationAndRelease(): void
    {
        $batchId = 'batch_global_001';

        $this->assertFalse($this->holdService->isGlobalHoldActive($batchId));
        $this->assertFalse($this->holdService->isAutomationSuppressed('service', 100));

        // Activate global hold
        $this->holdService->activateGlobalHold($batchId, 'Whmcs migration in progress');

        $this->assertTrue($this->holdService->isGlobalHoldActive($batchId));
        $this->assertTrue($this->holdService->isGlobalHoldActive());

        // Any entity is suppressed while global hold is active
        $this->assertTrue($this->holdService->isAutomationSuppressed('service', 100, 'suspend'));
        $this->assertTrue($this->holdService->isAutomationSuppressed('invoice', 500, 'renew'));
        $this->assertTrue($this->suppressionManager->isSuppressed('welcome_email', 'client', 10));

        // Release global hold
        $released = $this->holdService->releaseGlobalHold($batchId, 'admin', 'Migration completed successfully');
        $this->assertTrue($released);

        $this->assertFalse($this->holdService->isGlobalHoldActive($batchId));
        $this->assertFalse($this->holdService->isAutomationSuppressed('service', 100, 'suspend'));
    }

    public function testEntitySpecificHoldAndActionFiltering(): void
    {
        $batchId = 'batch_entity_002';

        // Hold service 201 only for destructive actions 'suspend' and 'terminate'
        $this->holdService->holdEntity(
            batchId: $batchId,
            entityType: 'service',
            entityId: 201,
            reason: 'Post-migration hold',
            suppressedActions: ['suspend', 'terminate']
        );

        $this->assertTrue($this->holdService->isEntityHeld('service', 201));
        $this->assertFalse($this->holdService->isEntityHeld('service', 202));

        // Check action filtering
        $this->assertTrue($this->holdService->isAutomationSuppressed('service', 201, 'suspend'));
        $this->assertTrue($this->holdService->isAutomationSuppressed('service', 201, 'terminate'));
        $this->assertFalse($this->holdService->isAutomationSuppressed('service', 201, 'renew'));

        // Service 202 is completely unsuppressed
        $this->assertFalse($this->holdService->isAutomationSuppressed('service', 202, 'suspend'));

        // Release entity
        $released = $this->holdService->releaseEntity('service', 201, 'operator', 'Verified hosting account');
        $this->assertTrue($released);
        $this->assertFalse($this->holdService->isEntityHeld('service', 201));
        $this->assertFalse($this->holdService->isAutomationSuppressed('service', 201, 'suspend'));
    }

    public function testNotificationSuppressionManagerInterceptsAndLogs(): void
    {
        $batchId = 'batch_notify_003';

        // Put client 301 under hold
        $this->holdService->holdEntity(
            batchId: $batchId,
            entityType: 'client',
            entityId: 301,
            reason: 'Migrated client silence period'
        );

        $this->assertTrue($this->suppressionManager->isSuppressed('invoice_created', 'client', 301));
        $this->assertFalse($this->suppressionManager->isSuppressed('invoice_created', 'client', 302));

        // Attempt to send email to held client 301
        $result = $this->suppressionManager->dispatchOrSuppress(
            batchId: $batchId,
            templateKey: 'invoice_created',
            data: [
                'customer_name' => 'Alice Corp',
                'invoice_number' => 'INV-2024-001',
                'total_formatted' => '$100.00',
                'due_date' => '2024-05-01',
            ],
            recipientEmail: 'alice@migration-test.com',
            recipientLocale: 'en',
            recipientName: 'Alice',
            recipientUserId: 301,
            entityType: 'client',
            entityId: 301
        );

        // Verification: Email was intercepted, not delivered
        $this->assertFalse($result->isSuccess());
        $this->assertSame('suppression_intercept', $result->getTransport());
        $this->assertTrue($result->getMetadata()['suppressed']);
        $this->assertCount(0, $this->mailTransport->getSentMessages());

        // Verification: Logged to suppressed_notifications table
        $suppressedList = $this->suppressionManager->getSuppressedNotifications($batchId);
        $this->assertCount(1, $suppressedList);
        $suppressed = $suppressedList[0];
        $this->assertSame('alice@migration-test.com', $suppressed->getRecipientEmail());
        $this->assertSame('invoice_created', $suppressed->getNotificationType());
        $this->assertSame('301', (string) $suppressed->getEntityId());
        $this->assertSame('suppressed', $suppressed->getStatus());

        // Dispatching to non-held client 302 should succeed and deliver to transport
        $resultUnheld = $this->suppressionManager->dispatchOrSuppress(
            batchId: $batchId,
            templateKey: 'invoice_created',
            data: [
                'customer_name' => 'Bob Corp',
                'invoice_number' => 'INV-2024-002',
                'total_formatted' => '$50.00',
                'due_date' => '2024-05-02',
            ],
            recipientEmail: 'bob@migration-test.com',
            recipientLocale: 'en',
            recipientName: 'Bob',
            recipientUserId: 302,
            entityType: 'client',
            entityId: 302
        );

        $this->assertTrue($resultUnheld->isSuccess());
        $this->assertCount(1, $this->mailTransport->getSentMessages());
    }

    public function testReplaySuppressedNotificationAfterHoldRelease(): void
    {
        $batchId = 'batch_replay_004';

        // Suppress a notification explicitly
        $suppressed = $this->suppressionManager->logSuppression(
            batchId: $batchId,
            recipientEmail: 'charlie@client.net',
            notificationType: 'invoice_created',
            entityType: 'client',
            entityId: 401,
            payload: [
                'template' => 'invoice_created',
                'data' => [
                    'customer_name' => 'Charlie',
                    'invoice_number' => 'INV-2024-003',
                    'total_formatted' => '$75.00',
                    'due_date' => '2024-05-10',
                ],
                'locale' => 'en',
                'recipient_name' => 'Charlie',
                'recipient_user_id' => 401,
            ]
        );

        $this->assertNotNull($suppressed->getId());
        $this->assertCount(0, $this->mailTransport->getSentMessages());

        // Replay suppressed notification
        $replayResult = $this->suppressionManager->replaySuppressed($suppressed->getId());
        $this->assertTrue($replayResult->isSuccess());
        $this->assertCount(1, $this->mailTransport->getSentMessages());

        // Check updated record status
        $updated = $this->suppressionRepo->find($suppressed->getId());
        $this->assertNotNull($updated);
        $this->assertSame('replayed', $updated->getStatus());
        $this->assertNotNull($updated->getReplayedAt());

        // Summary reflects replayed count
        $summary = $this->suppressionManager->getSummary($batchId);
        $this->assertSame(1, $summary['total_suppressed']);
        $this->assertSame(1, $summary['replayed']);
        $this->assertSame(0, $summary['pending']);
    }

    public function testBatchLevelEntitiesHoldAndSummary(): void
    {
        $batchId = 'batch_summary_005';

        $this->holdService->holdEntities($batchId, 'service', [10, 11, 12]);
        $this->holdService->holdEntities($batchId, 'domain', [20, 21]);
        $this->holdService->holdEntities($batchId, 'invoice', [30, 31, 32, 33]);

        $summary = $this->holdService->getHeldEntitiesSummary($batchId);
        $this->assertSame(9, $summary['total']);
        $this->assertSame(9, $summary['active']);
        $this->assertSame(0, $summary['released']);
        $this->assertSame(3, $summary['by_type']['service']['active']);
        $this->assertSame(2, $summary['by_type']['domain']['active']);
        $this->assertSame(4, $summary['by_type']['invoice']['active']);

        // Release batch
        $releasedCount = $this->holdService->releaseBatch($batchId, 'superadmin', 'Batch QA verified');
        $this->assertSame(9, $releasedCount);

        $summaryAfter = $this->holdService->getHeldEntitiesSummary($batchId);
        $this->assertSame(9, $summaryAfter['total']);
        $this->assertSame(0, $summaryAfter['active']);
        $this->assertSame(9, $summaryAfter['released']);
    }

    public function testOrchestratorLiveExecutionAppliesHoldToMigratedEntities(): void
    {
        // Setup mock WHMCS DB and connector
        $whmcsPdo = new PDO('sqlite::memory:');
        $whmcsDb = new Connection($whmcsPdo, 'sqlite');
        $whmcsConnector = new WhmcsReadOnlyConnector($whmcsDb);

        // Seed WHMCS tables
        $whmcsDb->statement('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, firstname TEXT, lastname TEXT, companyname TEXT, email TEXT, address1 TEXT, address2 TEXT, city TEXT, state TEXT, postcode TEXT, country TEXT, phonenumber TEXT, currency INT, status TEXT, datecreated TEXT, credit REAL DEFAULT 0.0)');
        $whmcsDb->statement('CREATE TABLE tblproducts (id INTEGER PRIMARY KEY, gid INT, type TEXT, name TEXT, description TEXT, paytype TEXT, servertype TEXT, autosetup TEXT)');
        $whmcsDb->statement('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, orderid INT, packageid INT, server INT, regdate TEXT, domain TEXT, paymentmethod TEXT, firstpaymentamount REAL, amount REAL, billingcycle TEXT, nextduedate TEXT, domainstatus TEXT, username TEXT, password TEXT, dedicatedip TEXT, assignedips TEXT, diskusage REAL, disklimit REAL, bwusage REAL, bwlimit REAL, lastupdate TEXT)');
        $whmcsDb->statement('CREATE TABLE tbldomains (id INTEGER PRIMARY KEY, userid INT, orderid INT, type TEXT, registrationdate TEXT, domain TEXT, firstpaymentamount REAL, recurringamount REAL, registrar TEXT, registrationperiod INT, expirydate TEXT, nextduedate TEXT, status TEXT, subscriptionid TEXT, dnsmanagement INT, emailforwarding INT, idprotection INT, donotrenew INT)');
        $whmcsDb->statement('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum TEXT, date TEXT, duedate TEXT, datepaid TEXT, subtotal REAL, credit REAL, tax REAL, tax2 REAL, total REAL, taxrate REAL, taxrate2 REAL, status TEXT, paymentmethod TEXT, notes TEXT)');
        $whmcsDb->statement('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, description TEXT, amount REAL, taxed INT, duedate TEXT, paymentmethod TEXT, notes TEXT)');
        $whmcsDb->statement('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, currency INT, gateway TEXT, date TEXT, description TEXT, amountin REAL, fees REAL, amountout REAL, transid TEXT, invoiceid INT, refundid INT)');
        $whmcsDb->statement('CREATE TABLE tblcredit (id INTEGER PRIMARY KEY, clientid INT, date TEXT, description TEXT, amount REAL, relid INT)');
        $whmcsDb->statement('CREATE TABLE tblticketdepartments (id INTEGER PRIMARY KEY, name TEXT, description TEXT, email TEXT, hidden INT, order_num INT)');
        $whmcsDb->statement('CREATE TABLE tbltickets (id INTEGER PRIMARY KEY, did INT, userid INT, contactid INT, name TEXT, email TEXT, date TEXT, title TEXT, message TEXT, status TEXT, urgency TEXT, admin TEXT, attachment TEXT, lastreply TEXT, flag INT, clientunread INT, adminunread INT)');
        $whmcsDb->statement('CREATE TABLE tblticketreplies (id INTEGER PRIMARY KEY, tid INT, userid INT, contactid INT, name TEXT, email TEXT, date TEXT, message TEXT, admin TEXT, attachment TEXT, rating INT)');

        // Populate seed data
        $whmcsDb->statement("INSERT INTO tblclients VALUES (1, 'John', 'Doe', 'Doe LLC', 'john@example.com', '123 Main', '', 'Anytown', 'CA', '90210', 'US', '555-1234', 1, 'Active', '2023-01-01', 0.0)");
        $whmcsDb->statement("INSERT INTO tblproducts VALUES (1, 1, 'hostingaccount', 'Basic Hosting', 'Shared plan', 'recurring', 'cpanel', 'order')");
        $whmcsDb->statement("INSERT INTO tblhosting VALUES (10, 1, 1, 1, 1, '2023-01-01', 'doe.com', 'stripe', 10.0, 10.0, 'Monthly', '2023-02-01', 'Active', 'johndoe', 'pass', '', '', 100, 1000, 50, 500, '2023-01-01')");
        $whmcsDb->statement("INSERT INTO tbldomains VALUES (20, 1, 1, 'Register', '2023-01-01', 'doe.com', 15.0, 15.0, 'enom', 1, '2024-01-01', '2024-01-01', 'Active', '', 1, 1, 0, 0)");
        $whmcsDb->statement("INSERT INTO tblinvoices VALUES (30, 1, 'INV-100', '2023-01-01', '2023-01-15', '2023-01-02', 25.0, 0.0, 0.0, 0.0, 25.0, 0.0, 0.0, 'Paid', 'stripe', 'Notes')");
        $whmcsDb->statement("INSERT INTO tblinvoiceitems VALUES (1, 30, 1, 'Hosting', 10, 'Basic Hosting Monthly', 10.0, 0, '2023-01-01', 'stripe', '')");
        $whmcsDb->statement("INSERT INTO tblaccounts VALUES (1, 1, 1, 'stripe', '2023-01-02', 'Payment for INV-100', 25.0, 0.5, 0.0, 'ch_test123', 30, 0)");

        // Staging and adoption setup on target DB
        $stagingRepo = new DatabaseStagingRepository($this->db);
        $mappingEngine = new GenericMappingEngine();
        $validationEngine = new CanonicalValidationEngine();
        $stagingPipeline = new StagingPipelineService($stagingRepo, $mappingEngine, $validationEngine);
        $identityResolver = new ProviderIdentityResolver();
        $adoptedRepo = new AdoptedIdentityRepository($this->db);
        $serviceAdopter = new ServiceAdoptionService($this->db, $adoptedRepo, $identityResolver);
        $domainAdopter = new DomainAdoptionService($this->db, $adoptedRepo, $identityResolver);

        $coreExtractor = new WhmcsCoreEntityExtractor($whmcsConnector);
        $financialExtractor = new WhmcsFinancialExtractor($whmcsConnector);
        $supportExtractor = new WhmcsSupportExtractor($whmcsConnector);

        $coreMigrator = new WhmcsCoreEntityMigrator(
            targetDb: $this->db,
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
            targetDb: $this->db,
            whmcs: $whmcsConnector,
            stagingRepo: $stagingRepo,
            stagingPipeline: $stagingPipeline,
            identityResolver: $identityResolver,
            extractor: $financialExtractor
        );

        $supportMigrator = new WhmcsSupportMigrator(
            targetDb: $this->db,
            whmcs: $whmcsConnector,
            stagingRepo: $stagingRepo,
            stagingPipeline: $stagingPipeline,
            identityResolver: $identityResolver,
            extractor: $supportExtractor
        );

        $conflictDetector = new ConflictDetector($this->db);
        $checkpointRepo = new MigrationCheckpointRepository($this->db);
        $quarantineManager = new QuarantineManager($stagingRepo, $stagingPipeline);

        $orchestrator = new MigrationExecutionOrchestrator(
            targetDb: $this->db,
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

        $batchId = 'batch_live_hold_test';
        $result = $orchestrator->executeLive($batchId, ['apply_migration_hold' => true]);

        $this->assertSame('completed', $result['status']);
        $this->assertTrue($result['zero_silent_loss_achieved']);
        $this->assertNotNull($result['migration_hold_summary']);

        $holdSummary = $result['migration_hold_summary'];
        $this->assertSame(5, $holdSummary['total']);
        $this->assertSame(4, $holdSummary['active']);
        $this->assertSame(1, $holdSummary['released']);
        $this->assertFalse($this->holdService->isGlobalHoldActive($batchId));

        // Check that migrated services and clients are held and protected from automation
        $serviceId = (int) $result['steps']['services']['service_ids']['10'];
        $clientId = (int) $result['steps']['clients']['user_ids']['1'];

        $this->assertTrue($this->holdService->isEntityHeld('service', $serviceId));
        $this->assertTrue($this->holdService->isEntityHeld('client', $clientId));

        // Automation is suppressed for this service
        $this->assertTrue($this->holdService->isAutomationSuppressed('service', $serviceId, 'suspend'));

        // Notification to this client is intercepted
        $this->assertTrue($this->suppressionManager->isSuppressed('invoice_created', 'client', $clientId));
    }
}
