<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Migration;

use Coleza\Domain\Migration\Adoption\AdoptedIdentityRepository;
use Coleza\Domain\Migration\Adoption\DomainAdoptionService;
use Coleza\Domain\Migration\Adoption\ProviderIdentityResolver;
use Coleza\Domain\Migration\Adoption\ServiceAdoptionService;
use Coleza\Domain\Migration\Execution\ConflictDetector;
use Coleza\Domain\Migration\Execution\MigrationCheckpoint;
use Coleza\Domain\Migration\Execution\MigrationCheckpointRepository;
use Coleza\Domain\Migration\Execution\MigrationExecutionOrchestrator;
use Coleza\Domain\Migration\Execution\QuarantineManager;
use Coleza\Domain\Migration\Mapping\GenericMappingEngine;
use Coleza\Domain\Migration\Staging\DatabaseStagingRepository;
use Coleza\Domain\Migration\Staging\StagingPipelineService;
use Coleza\Domain\Migration\Staging\StagingRecordStatus;
use Coleza\Domain\Migration\Validation\CanonicalValidationEngine;
use Coleza\Domain\Migration\Whmcs\Core\WhmcsCoreEntityExtractor;
use Coleza\Domain\Migration\Whmcs\Core\WhmcsCoreEntityMigrator;
use Coleza\Domain\Migration\Whmcs\Financial\WhmcsFinancialExtractor;
use Coleza\Domain\Migration\Whmcs\Financial\WhmcsFinancialMigrator;
use Coleza\Domain\Migration\Whmcs\Support\UnsupportedDataAccountant;
use Coleza\Domain\Migration\Whmcs\Support\WhmcsSupportExtractor;
use Coleza\Domain\Migration\Whmcs\Support\WhmcsSupportMigrator;
use Coleza\Domain\Migration\Whmcs\WhmcsReadOnlyConnector;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class DryRunConflictCheckpointResumeTest extends TestCase
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

    private ConflictDetector $conflictDetector;
    private MigrationCheckpointRepository $checkpointRepo;
    private QuarantineManager $quarantineManager;
    private MigrationExecutionOrchestrator $orchestrator;

    protected function setUp(): void
    {
        parent::setUp();

        $whmcsPdo = new PDO('sqlite::memory:');
        $this->whmcsDb = new Connection($whmcsPdo, 'sqlite');
        $this->whmcsConnector = new WhmcsReadOnlyConnector($this->whmcsDb);

        $targetPdo = new PDO('sqlite::memory:');
        $this->targetDb = new Connection($targetPdo, 'sqlite');

        $this->stagingRepo = new DatabaseStagingRepository($this->targetDb);
        $this->mappingEngine = new GenericMappingEngine();
        $this->validationEngine = new CanonicalValidationEngine();
        $this->stagingPipeline = new StagingPipelineService(
            $this->stagingRepo,
            $this->mappingEngine,
            $this->validationEngine
        );

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
            extractor: $this->supportExtractor,
            accountant: new UnsupportedDataAccountant()
        );

        $this->conflictDetector = new ConflictDetector($this->targetDb, $this->adoptedRepo);
        $this->checkpointRepo = new MigrationCheckpointRepository($this->targetDb);
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
            supportExtractor: $this->supportExtractor
        );

        $this->seedSourceWhmcsDatabase();
    }

    private function seedSourceWhmcsDatabase(): void
    {
        $this->whmcsDb->statement('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code VARCHAR(10))');
        $this->whmcsDb->statement("INSERT INTO tblcurrencies VALUES (1, 'USD')");

        $this->whmcsDb->statement('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, firstname VARCHAR(50), lastname VARCHAR(50), companyname VARCHAR(100), email VARCHAR(191), currency INT, credit DECIMAL(10,2) DEFAULT 0.00, status VARCHAR(20), datecreated DATETIME)');
        $this->whmcsDb->statement("INSERT INTO tblclients (id, firstname, lastname, email, currency, credit, status) VALUES (1, 'John', 'Doe', 'john@test.com', 1, 25.00, 'Active')");

        $this->whmcsDb->statement('CREATE TABLE tblproductgroups (id INTEGER PRIMARY KEY, name VARCHAR(100), slug VARCHAR(100))');
        $this->whmcsDb->statement("INSERT INTO tblproductgroups VALUES (1, 'Web Hosting', 'hosting')");

        $this->whmcsDb->statement('CREATE TABLE tblproducts (id INTEGER PRIMARY KEY, gid INT, type VARCHAR(50), name VARCHAR(100), description TEXT)');
        $this->whmcsDb->statement("INSERT INTO tblproducts VALUES (10, 1, 'hostingaccount', 'Starter Hosting', 'Desc')");

        $this->whmcsDb->statement('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, packageid INT, server INT, domain VARCHAR(255), username VARCHAR(50), amount DECIMAL(10,2), domainstatus VARCHAR(30))');
        $this->whmcsDb->statement("INSERT INTO tblhosting VALUES (101, 1, 10, 1, 'johndoe.com', 'johndoe', 10.00, 'Active')");

        $this->whmcsDb->statement('CREATE TABLE tbldomains (id INTEGER PRIMARY KEY, userid INT, domain VARCHAR(255), recurringamount DECIMAL(10,2), registrar VARCHAR(50), status VARCHAR(30))');
        $this->whmcsDb->statement("INSERT INTO tbldomains VALUES (201, 1, 'johndoe.com', 12.00, 'enom', 'Active')");

        $this->whmcsDb->statement('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum VARCHAR(50), subtotal DECIMAL(10,2), total DECIMAL(10,2), status VARCHAR(30), currency INT)');
        $this->whmcsDb->statement("INSERT INTO tblinvoices VALUES (301, 1, 'INV-1001', 22.00, 22.00, 'Paid', 1)");

        $this->whmcsDb->statement('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, currency INT, gateway VARCHAR(50), amountin DECIMAL(10,2), invoiceid INT)');
        $this->whmcsDb->statement("INSERT INTO tblaccounts VALUES (401, 1, 1, 'stripe', 22.00, 301)");

        $this->whmcsDb->statement('CREATE TABLE tblticketdepartments (id INTEGER PRIMARY KEY, name VARCHAR(100), email VARCHAR(150))');
        $this->whmcsDb->statement("INSERT INTO tblticketdepartments VALUES (1, 'Support', 'support@test.com')");

        $this->whmcsDb->statement('CREATE TABLE tbltickets (id INTEGER PRIMARY KEY, tid VARCHAR(50), did INT, userid INT, title VARCHAR(255), message TEXT, status VARCHAR(30), urgency VARCHAR(30))');
        $this->whmcsDb->statement("INSERT INTO tbltickets VALUES (501, 'TICK-101', 1, 1, 'Welcome Inquiry', 'Hello', 'Open', 'Low')");
    }

    public function testDryRunSimulationDoesNotMutateTargetTables(): void
    {
        $batchId = 'dry-run-batch-01';
        $report = $this->orchestrator->executeDryRun($batchId);

        $this->assertTrue($report->isDryRun());
        $this->assertTrue($report->canProceedSafely());
        $this->assertFalse($report->hasConflicts());

        $this->assertSame(1, $report->getInspectedByType()['clients']);
        $this->assertSame(1, $report->getInspectedByType()['products']);
        $this->assertSame(1, $report->getInspectedByType()['services']);
        $this->assertSame(1, $report->getInspectedByType()['domains']);
        $this->assertSame(1, $report->getInspectedByType()['invoices']);
        $this->assertSame(1, $report->getInspectedByType()['payments']);
        $this->assertSame(1, $report->getInspectedByType()['credits']);

        // Verify that target tables remain completely empty (0 records inserted)
        $userCount = $this->targetDb->selectOne('SELECT count(*) as cnt FROM users');
        $this->assertSame(0, (int) $userCount['cnt']);

        $serviceCount = $this->targetDb->selectOne('SELECT count(*) as cnt FROM services');
        $this->assertSame(0, (int) $serviceCount['cnt']);

        $domainCount = $this->targetDb->selectOne('SELECT count(*) as cnt FROM domains');
        $this->assertSame(0, (int) $domainCount['cnt']);

        $invoiceCount = $this->targetDb->selectOne('SELECT count(*) as cnt FROM invoices');
        $this->assertSame(0, (int) $invoiceCount['cnt']);

        $paymentCount = $this->targetDb->selectOne('SELECT count(*) as cnt FROM payments');
        $this->assertSame(0, (int) $paymentCount['cnt']);
    }

    public function testPreFlightConflictDetection(): void
    {
        // Pre-populate target DB with conflicting user and domain
        $this->coreMigrator->ensureTargetTables();
        $this->targetDb->statement(
            "INSERT INTO users (email, password_hash, name, is_active) VALUES ('john@test.com', 'hash', 'Existing John', 1)"
        );
        $this->targetDb->statement(
            "INSERT INTO domains (user_id, domain_name, registrar, status) VALUES (1, 'johndoe.com', 'other_registrar', 'active')"
        );

        $batchId = 'dry-run-conflict-01';
        $report = $this->orchestrator->executeDryRun($batchId);

        $this->assertTrue($report->hasConflicts());
        $conflicts = $report->getConflictsDetected();

        $this->assertCount(1, $conflicts['users']);
        $this->assertSame('john@test.com', $conflicts['users'][0]['email']);

        $this->assertCount(1, $conflicts['domains']);
        $this->assertSame('johndoe.com', $conflicts['domains'][0]['domain']);
    }

    public function testCheckpointSaveAndResumeFromInterruptedStep(): void
    {
        $batchId = 'checkpoint-resume-01';
        $this->checkpointRepo->ensureTable();

        // 1. Manually set checkpoint at 'invoices' (as if clients, products, services, domains are done)
        // We prepopulate resolver so invoices have mapped client
        $this->identityResolver->registerClientMapping('1', 501);

        $checkpoint = new MigrationCheckpoint(
            batchId: $batchId,
            currentStep: 'invoices',
            status: 'in_progress'
        );
        $this->checkpointRepo->save($checkpoint);

        // 2. Run live orchestrator: it should resume from 'invoices'
        $result = $this->orchestrator->executeLive($batchId);

        $this->assertSame('completed', $result['status']);
        $this->assertArrayHasKey('invoices', $result['steps']);
        $this->assertArrayHasKey('payments', $result['steps']);
        $this->assertArrayHasKey('credits', $result['steps']);
        $this->assertArrayHasKey('tickets', $result['steps']);
        $this->assertArrayNotHasKey('clients', $result['steps']); // Was skipped because resumed after

        // Checkpoint in DB must now be marked 'completed'
        $savedCp = $this->checkpointRepo->find($batchId);
        $this->assertNotNull($savedCp);
        $this->assertSame('completed', $savedCp->getStatus());
    }

    public function testQuarantineManagerInspectionAndRemediation(): void
    {
        $batchId = 'quarantine-test-01';

        // Stage an invalid client with malformed email
        $rawClient = ['id' => 99, 'firstname' => 'Bad', 'lastname' => 'Email', 'email' => 'invalid-email'];
        $record = $this->stagingPipeline->stageRawRecord($batchId, 'whmcs', 'client', '99', $rawClient);
        $this->stagingPipeline->transformAndValidate($record);

        $this->assertSame(StagingRecordStatus::QUARANTINED, $record->getStatus());

        // QuarantineManager inspects quarantined records
        $quarantined = $this->quarantineManager->getQuarantinedRecords($batchId, 'client');
        $this->assertCount(1, $quarantined);

        $summary = $this->quarantineManager->getQuarantineSummary($batchId);
        $this->assertSame(1, $summary['total_quarantined']);
        $this->assertSame(1, $summary['by_entity_type']['client']);

        // Remediate with corrected valid email
        $remediated = $this->quarantineManager->remediate((int) $record->getId(), ['email' => 'corrected@valid.com']);
        $this->assertSame(StagingRecordStatus::VALIDATED, $remediated->getStatus());
        $this->assertEmpty($remediated->getValidationErrors());
    }

    public function testAbsoluteIdempotencyOnRepeatedExecution(): void
    {
        $batchId = 'idempotent-batch-01';

        // First run
        $result1 = $this->orchestrator->executeLive($batchId);
        $this->assertTrue($result1['zero_silent_loss_achieved']);
        $this->assertTrue($result1['fully_terminal']);

        $userCount1 = (int) $this->targetDb->selectOne('SELECT count(*) as cnt FROM users')['cnt'];
        $serviceCount1 = (int) $this->targetDb->selectOne('SELECT count(*) as cnt FROM services')['cnt'];
        $domainCount1 = (int) $this->targetDb->selectOne('SELECT count(*) as cnt FROM domains')['cnt'];
        $invoiceCount1 = (int) $this->targetDb->selectOne('SELECT count(*) as cnt FROM invoices')['cnt'];
        $paymentCount1 = (int) $this->targetDb->selectOne('SELECT count(*) as cnt FROM payments')['cnt'];

        // Second run with the same batch ID (or rerun)
        $result2 = $this->orchestrator->executeLive($batchId);
        $this->assertTrue($result2['zero_silent_loss_achieved']);

        $userCount2 = (int) $this->targetDb->selectOne('SELECT count(*) as cnt FROM users')['cnt'];
        $serviceCount2 = (int) $this->targetDb->selectOne('SELECT count(*) as cnt FROM services')['cnt'];
        $domainCount2 = (int) $this->targetDb->selectOne('SELECT count(*) as cnt FROM domains')['cnt'];
        $invoiceCount2 = (int) $this->targetDb->selectOne('SELECT count(*) as cnt FROM invoices')['cnt'];
        $paymentCount2 = (int) $this->targetDb->selectOne('SELECT count(*) as cnt FROM payments')['cnt'];

        // Quantities must be identical without duplicates
        $this->assertSame($userCount1, $userCount2);
        $this->assertSame($serviceCount1, $serviceCount2);
        $this->assertSame($domainCount1, $domainCount2);
        $this->assertSame($invoiceCount1, $invoiceCount2);
        $this->assertSame($paymentCount1, $paymentCount2);
    }
}
