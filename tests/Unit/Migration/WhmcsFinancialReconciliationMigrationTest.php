<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Migration;

use Coleza\Domain\Migration\Adoption\ProviderIdentityResolver;
use Coleza\Domain\Migration\Mapping\GenericMappingEngine;
use Coleza\Domain\Migration\Staging\DatabaseStagingRepository;
use Coleza\Domain\Migration\Staging\StagingPipelineService;
use Coleza\Domain\Migration\Staging\StagingRecordStatus;
use Coleza\Domain\Migration\Validation\CanonicalValidationEngine;
use Coleza\Domain\Migration\Whmcs\Financial\WhmcsFinancialExtractor;
use Coleza\Domain\Migration\Whmcs\Financial\WhmcsFinancialMigrator;
use Coleza\Domain\Migration\Whmcs\WhmcsReadOnlyConnector;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class WhmcsFinancialReconciliationMigrationTest extends TestCase
{
    private Connection $whmcsDb;
    private Connection $targetDb;
    private WhmcsReadOnlyConnector $whmcsConnector;
    private DatabaseStagingRepository $stagingRepo;
    private StagingPipelineService $stagingPipeline;
    private GenericMappingEngine $mappingEngine;
    private CanonicalValidationEngine $validationEngine;
    private ProviderIdentityResolver $identityResolver;
    private WhmcsFinancialExtractor $extractor;
    private WhmcsFinancialMigrator $migrator;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Source WHMCS DB in memory
        $whmcsPdo = new PDO('sqlite::memory:');
        $this->whmcsDb = new Connection($whmcsPdo, 'sqlite');
        $this->whmcsConnector = new WhmcsReadOnlyConnector($this->whmcsDb);

        // 2. Target Coleza DB in memory
        $targetPdo = new PDO('sqlite::memory:');
        $this->targetDb = new Connection($targetPdo, 'sqlite');

        // 3. Staging and validation
        $this->stagingRepo = new DatabaseStagingRepository($this->targetDb);
        $this->mappingEngine = new GenericMappingEngine();
        $this->validationEngine = new CanonicalValidationEngine();
        $this->stagingPipeline = new StagingPipelineService(
            $this->stagingRepo,
            $this->mappingEngine,
            $this->validationEngine
        );

        // 4. Resolver
        $this->identityResolver = new ProviderIdentityResolver();

        // 5. Financial Extractor & Migrator
        $this->extractor = new WhmcsFinancialExtractor($this->whmcsConnector);
        $this->migrator = new WhmcsFinancialMigrator(
            targetDb: $this->targetDb,
            whmcs: $this->whmcsConnector,
            stagingRepo: $this->stagingRepo,
            stagingPipeline: $this->stagingPipeline,
            identityResolver: $this->identityResolver,
            extractor: $this->extractor
        );

        // 6. Ensure target schema
        $this->migrator->ensureFinancialTables();

        // 7. Create source WHMCS financial schema
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
            email VARCHAR(191),
            currency INT,
            credit DECIMAL(10,2) DEFAULT 0.00
        )');

        $this->whmcsDb->statement('CREATE TABLE tblinvoices (
            id INTEGER PRIMARY KEY,
            userid INT,
            invoicenum VARCHAR(50),
            date DATE,
            duedate DATE,
            datepaid DATETIME,
            subtotal DECIMAL(10,2),
            credit DECIMAL(10,2) DEFAULT 0.00,
            tax DECIMAL(10,2) DEFAULT 0.00,
            tax2 DECIMAL(10,2) DEFAULT 0.00,
            total DECIMAL(10,2),
            status VARCHAR(30),
            paymentmethod VARCHAR(50),
            notes TEXT,
            currency INT
        )');

        $this->whmcsDb->statement('CREATE TABLE tblinvoiceitems (
            id INTEGER PRIMARY KEY,
            invoiceid INT,
            userid INT,
            type VARCHAR(30),
            relid INT,
            description TEXT,
            amount DECIMAL(10,2),
            taxed INT
        )');

        $this->whmcsDb->statement('CREATE TABLE tblaccounts (
            id INTEGER PRIMARY KEY,
            userid INT,
            currency INT,
            gateway VARCHAR(50),
            date DATETIME,
            description TEXT,
            amountin DECIMAL(10,2) DEFAULT 0.00,
            fees DECIMAL(10,2) DEFAULT 0.00,
            amountout DECIMAL(10,2) DEFAULT 0.00,
            transid VARCHAR(100),
            invoiceid INT
        )');
    }

    public function testExtractFinancialEntities(): void
    {
        $this->whmcsDb->statement("INSERT INTO tblcurrencies VALUES (1, 'USD', '$', '', 1.0, 1)");
        $this->whmcsDb->statement("INSERT INTO tblcurrencies VALUES (2, 'EUR', '€', '', 0.9, 0)");

        $this->whmcsDb->statement("INSERT INTO tblclients VALUES (1, 'John', 'Doe', 'john@test.com', 1, 50.00)");
        $this->whmcsDb->statement("INSERT INTO tblclients VALUES (2, 'Maria', 'Garcia', 'maria@test.com', 2, 0.00)");

        $this->whmcsDb->statement("INSERT INTO tblinvoices (id, userid, invoicenum, date, duedate, subtotal, tax, total, status, currency)
            VALUES (101, 1, 'INV-101', '2025-01-01', '2025-01-15', 100.00, 20.00, 120.00, 'Paid', 1)");

        $this->whmcsDb->statement("INSERT INTO tblinvoiceitems (id, invoiceid, userid, description, amount)
            VALUES (1, 101, 1, 'Web Hosting Annual', 100.00)");

        $this->whmcsDb->statement("INSERT INTO tblaccounts (id, userid, currency, gateway, date, amountin, fees, transid, invoiceid)
            VALUES (501, 1, 1, 'stripe', '2025-01-02 10:00:00', 120.00, 3.50, 'ch_test123', 101)");

        $invoices = $this->extractor->extractInvoices();
        $this->assertCount(1, $invoices);
        $this->assertSame('USD', $invoices[0]['currency']);
        $this->assertCount(1, $invoices[0]['items']);
        $this->assertSame(100.00, (float)$invoices[0]['items'][0]['amount']);

        $txns = $this->extractor->extractTransactions();
        $this->assertCount(1, $txns);
        $this->assertSame(120.00, (float)$txns[0]['amountin']);
        $this->assertSame('USD', $txns[0]['currency']);

        $credits = $this->extractor->extractClientCredits();
        $this->assertCount(1, $credits);
        $this->assertSame(50.00, $credits[0]['credit']);
        $this->assertSame('USD', $credits[0]['currency']);

        $invTotals = $this->extractor->calculateSourceInvoiceTotals();
        $this->assertSame(120.00, $invTotals['USD']);

        $payTotals = $this->extractor->calculateSourcePaymentTotals();
        $this->assertSame(120.00, $payTotals['USD']);

        $credTotals = $this->extractor->calculateSourceCreditTotals();
        $this->assertSame(50.00, $credTotals['USD']);
    }

    public function testMigrateInvoicesWithItemsAndPreserveMinorUnits(): void
    {
        $this->identityResolver->registerClientMapping('1', 501);

        $this->whmcsDb->statement("INSERT INTO tblcurrencies VALUES (1, 'USD', '$', '', 1.0, 1)");
        $this->whmcsDb->statement("INSERT INTO tblclients VALUES (1, 'John', 'Doe', 'john@test.com', 1, 0.00)");
        $this->whmcsDb->statement("INSERT INTO tblinvoices (id, userid, invoicenum, date, duedate, subtotal, tax, tax2, total, status, currency)
            VALUES (201, 1, 'INV-201', '2025-02-01', '2025-02-15', 75.50, 7.55, 3.78, 86.83, 'Paid', 1)");

        $this->whmcsDb->statement("INSERT INTO tblinvoiceitems (id, invoiceid, userid, description, amount)
            VALUES (11, 201, 1, 'Pro Hosting Package', 50.00)");
        $this->whmcsDb->statement("INSERT INTO tblinvoiceitems (id, invoiceid, userid, description, amount)
            VALUES (12, 201, 1, 'Domain Registration (.com)', 25.50)");

        $batchId = 'batch-inv-01';
        $stats = $this->migrator->migrateInvoices($batchId);

        $this->assertSame(1, $stats['staged']);
        $this->assertSame(1, $stats['migrated']);
        $this->assertSame(0, $stats['quarantined']);
        $this->assertSame(86.83, $stats['amounts_by_currency']['USD']);

        // Check target database
        $invoice = $this->targetDb->selectOne("SELECT * FROM invoices WHERE invoice_number = 'INV-201'");
        $this->assertNotNull($invoice);
        $this->assertSame(501, (int)$invoice['user_id']);
        $this->assertSame('USD', $invoice['currency_code']);
        $this->assertSame(7550, (int)$invoice['subtotal_minor']);
        $this->assertSame(1133, (int)$invoice['tax_total_minor']); // 7.55 + 3.78 = 11.33
        $this->assertSame(8683, (int)$invoice['total_minor']);
        $this->assertSame('paid', $invoice['status']);

        // Check items
        $items = $this->targetDb->select("SELECT * FROM invoice_items WHERE invoice_id = ?", [(int)$invoice['id']]);
        $this->assertCount(2, $items);
        $this->assertSame(5000, (int)$items[0]['subtotal_minor']);
        $this->assertSame(2550, (int)$items[1]['subtotal_minor']);

        // Staging record
        $records = $this->stagingRepo->getBatchRecords($batchId);
        $this->assertCount(1, $records);
        $this->assertSame(StagingRecordStatus::MIGRATED, $records[0]->getStatus());
        $this->assertSame((int)$invoice['id'], $records[0]->getTargetEntityId());
    }

    public function testMigratePaymentsAndCreateAllocations(): void
    {
        $this->identityResolver->registerClientMapping('1', 501);

        $this->whmcsDb->statement("INSERT INTO tblcurrencies VALUES (1, 'USD', '$', '', 1.0, 1)");
        $this->whmcsDb->statement("INSERT INTO tblclients VALUES (1, 'John', 'Doe', 'john@test.com', 1, 0.00)");
        $this->whmcsDb->statement("INSERT INTO tblinvoices (id, userid, invoicenum, subtotal, total, status, currency)
            VALUES (301, 1, 'INV-301', 50.00, 50.00, 'Unpaid', 1)");

        $this->whmcsDb->statement("INSERT INTO tblaccounts (id, userid, currency, gateway, date, amountin, fees, transid, invoiceid)
            VALUES (701, 1, 1, 'stripe', '2025-02-10 14:00:00', 50.00, 1.75, 'ch_test50', 301)");

        $batchId = 'batch-pay-01';

        // 1. Migrate invoice first so it can be allocated
        $this->migrator->migrateInvoices($batchId);
        $targetInvId = $this->migrator->getInvoiceMapping('301');
        $this->assertNotNull($targetInvId);

        // 2. Migrate payment
        $payStats = $this->migrator->migratePayments($batchId);
        $this->assertSame(1, $payStats['staged']);
        $this->assertSame(1, $payStats['migrated']);
        $this->assertSame(50.00, $payStats['amounts_by_currency']['USD']);

        // Check target payment
        $payment = $this->targetDb->selectOne("SELECT * FROM payments WHERE payment_number = 'WHMCS-PAY-701'");
        $this->assertNotNull($payment);
        $this->assertSame(501, (int)$payment['user_id']);
        $this->assertSame(5000, (int)$payment['amount_minor']);
        $this->assertSame(175, (int)$payment['fee_minor']);
        $this->assertSame(4825, (int)$payment['net_amount_minor']);
        $this->assertSame('stripe', $payment['payment_method']);
        $this->assertSame('ch_test50', $payment['transaction_reference']);

        // Check allocation
        $alloc = $this->targetDb->selectOne("SELECT * FROM payment_allocations WHERE payment_id = ?", [(int)$payment['id']]);
        $this->assertNotNull($alloc);
        $this->assertSame($targetInvId, (int)$alloc['invoice_id']);
        $this->assertSame(5000, (int)$alloc['amount_minor']);

        // Check invoice paid amount updated
        $inv = $this->targetDb->selectOne("SELECT * FROM invoices WHERE id = ?", [$targetInvId]);
        $this->assertSame(5000, (int)$inv['paid_amount_minor']);
    }

    public function testMigrateClientCreditsIntoCreditLedger(): void
    {
        $this->identityResolver->registerClientMapping('10', 510);

        $this->whmcsDb->statement("INSERT INTO tblcurrencies VALUES (1, 'USD', '$', '', 1.0, 1)");
        $this->whmcsDb->statement("INSERT INTO tblclients VALUES (10, 'Rich', 'Client', 'rich@client.com', 1, 150.25)");

        $batchId = 'batch-cred-01';
        $stats = $this->migrator->migrateCredits($batchId);

        $this->assertSame(1, $stats['staged']);
        $this->assertSame(1, $stats['migrated']);
        $this->assertSame(150.25, $stats['amounts_by_currency']['USD']);

        // Check target credit ledger
        $ledger = $this->targetDb->selectOne("SELECT * FROM credit_ledger WHERE user_id = 510");
        $this->assertNotNull($ledger);
        $this->assertSame(15025, (int)$ledger['amount_minor']);
        $this->assertSame(15025, (int)$ledger['balance_after_minor']);
        $this->assertSame('deposit', $ledger['type']);
        $this->assertSame('Migrated WHMCS credit balance', $ledger['reason']);

        // Staging record
        $records = $this->stagingRepo->getBatchRecords($batchId);
        $this->assertCount(1, $records);
        $this->assertSame(StagingRecordStatus::MIGRATED, $records[0]->getStatus());
    }

    public function testEndToEndFinancialReconciliationZeroDiff(): void
    {
        // Setup currencies: 1=USD, 2=EUR
        $this->whmcsDb->statement("INSERT INTO tblcurrencies VALUES (1, 'USD', '$', '', 1.0, 1)");
        $this->whmcsDb->statement("INSERT INTO tblcurrencies VALUES (2, 'EUR', '€', '', 0.9, 0)");

        // Setup clients: 1 (USD) mapped to 601, 2 (EUR) mapped to 602
        $this->identityResolver->registerClientMapping('1', 601);
        $this->identityResolver->registerClientMapping('2', 602);

        $this->whmcsDb->statement("INSERT INTO tblclients VALUES (1, 'US Client', 'One', 'us@client.com', 1, 45.00)");
        $this->whmcsDb->statement("INSERT INTO tblclients VALUES (2, 'EU Client', 'Two', 'eu@client.com', 2, 30.00)");

        // USD Invoice & EUR Invoice
        $this->whmcsDb->statement("INSERT INTO tblinvoices (id, userid, invoicenum, subtotal, tax, total, status, currency)
            VALUES (1001, 1, 'INV-USD-1', 200.00, 20.00, 220.00, 'Paid', 1)");
        $this->whmcsDb->statement("INSERT INTO tblinvoices (id, userid, invoicenum, subtotal, tax, total, status, currency)
            VALUES (1002, 2, 'INV-EUR-1', 100.00, 19.00, 119.00, 'Paid', 2)");

        // USD Payment & EUR Payment
        $this->whmcsDb->statement("INSERT INTO tblaccounts (id, userid, currency, gateway, amountin, fees, transid, invoiceid)
            VALUES (8001, 1, 1, 'stripe', 220.00, 5.00, 'ch_usd', 1001)");
        $this->whmcsDb->statement("INSERT INTO tblaccounts (id, userid, currency, gateway, amountin, fees, transid, invoiceid)
            VALUES (8002, 2, 2, 'paypal', 119.00, 3.50, 'ch_eur', 1002)");

        $batchId = 'e2e-financial-batch-01';
        $report = $this->migrator->migrateAndReconcile($batchId);

        // Financial Ledger Zero-Diff Verification
        $this->assertTrue($report->isFinancialReconciled(), 'Financial reconciliation must achieve zero diff');

        $invRecon = $report->getInvoiceReconciliation();
        $this->assertTrue($invRecon['is_reconciled']);
        $this->assertSame(0.00, $invRecon['diff']['USD']);
        $this->assertSame(0.00, $invRecon['diff']['EUR']);
        $this->assertSame(220.00, $invRecon['source']['USD']);
        $this->assertSame(119.00, $invRecon['source']['EUR']);
        $this->assertSame(220.00, $invRecon['target']['USD']);
        $this->assertSame(119.00, $invRecon['target']['EUR']);

        $payRecon = $report->getPaymentReconciliation();
        $this->assertTrue($payRecon['is_reconciled']);
        $this->assertSame(0.00, $payRecon['diff']['USD']);
        $this->assertSame(0.00, $payRecon['diff']['EUR']);
        $this->assertSame(220.00, $payRecon['source']['USD']);
        $this->assertSame(119.00, $payRecon['source']['EUR']);

        $credRecon = $report->getCreditReconciliation();
        $this->assertTrue($credRecon['is_reconciled']);
        $this->assertSame(0.00, $credRecon['diff']['USD']);
        $this->assertSame(0.00, $credRecon['diff']['EUR']);
        $this->assertSame(45.00, $credRecon['source']['USD']);
        $this->assertSame(30.00, $credRecon['source']['EUR']);

        // Zero Silent Loss Verification
        $this->assertTrue($report->isZeroSilentLossAchieved());
        $this->assertTrue($report->isFullyTerminal());
        $this->assertTrue($report->isCertified());
        $this->assertSame(0, $report->getStagingReport()->getUnaccountedDiff());
        $this->assertSame(0, $report->getStagingReport()->getInFlightCount());

        // Total 2 invoices + 2 payments + 2 credits = 6 staged & migrated
        $this->assertSame(6, $report->getStagingReport()->getTotalStagedRecords());
        $this->assertSame(6, $report->getStagingReport()->getMigratedCount());
        $this->assertSame(0, $report->getStagingReport()->getQuarantinedCount());
    }

    public function testQuarantineUnresolvedClientFinancials(): void
    {
        $this->whmcsDb->statement("INSERT INTO tblcurrencies VALUES (1, 'USD', '$', '', 1.0, 1)");

        // Client 99 is NOT registered in identityResolver
        $this->whmcsDb->statement("INSERT INTO tblinvoices (id, userid, invoicenum, subtotal, total, status, currency)
            VALUES (901, 99, 'INV-ORPHAN', 50.00, 50.00, 'Unpaid', 1)");

        $batchId = 'batch-orphan-financial-01';
        $stats = $this->migrator->migrateInvoices($batchId);

        $this->assertSame(1, $stats['staged']);
        $this->assertSame(0, $stats['migrated']);
        $this->assertSame(1, $stats['quarantined']);

        $quarantined = $this->stagingRepo->getBatchRecords($batchId, StagingRecordStatus::QUARANTINED);
        $this->assertCount(1, $quarantined);
        $this->assertStringContainsString('unmigrated client [99]', (string)$quarantined[0]->getQuarantineReason());

        $report = $this->stagingRepo->generateAccountingReport($batchId);
        $this->assertSame(0, $report->getUnaccountedDiff());
        $this->assertTrue($report->isZeroSilentLossAchieved());
    }

    public function testInvoiceMathValidationQuarantine(): void
    {
        $this->identityResolver->registerClientMapping('1', 501);

        $this->whmcsDb->statement("INSERT INTO tblcurrencies VALUES (1, 'USD', '$', '', 1.0, 1)");

        // Mathematical corruption: subtotal 50 + tax 0 != total 200 (violates abs(total - expected) <= 0.05)
        $this->whmcsDb->statement("INSERT INTO tblinvoices (id, userid, invoicenum, subtotal, tax, total, status, currency)
            VALUES (902, 1, 'INV-MATH-ERR', 50.00, 0.00, 200.00, 'Unpaid', 1)");

        $batchId = 'batch-math-err-01';
        $stats = $this->migrator->migrateInvoices($batchId);

        $this->assertSame(1, $stats['staged']);
        $this->assertSame(0, $stats['migrated']);
        $this->assertSame(1, $stats['quarantined']);

        $quarantined = $this->stagingRepo->getBatchRecords($batchId, StagingRecordStatus::QUARANTINED);
        $this->assertCount(1, $quarantined);
        $this->assertStringContainsString('validation failure', (string)$quarantined[0]->getQuarantineReason());
        $this->assertStringContainsString('Invoice math inconsistency', implode(' ', $quarantined[0]->getValidationErrors()));

        $report = $this->stagingRepo->generateAccountingReport($batchId);
        $this->assertSame(0, $report->getUnaccountedDiff());
        $this->assertTrue($report->isZeroSilentLossAchieved());
    }
}
