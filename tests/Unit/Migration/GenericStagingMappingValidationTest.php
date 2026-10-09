<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Migration;

use Coleza\Domain\Migration\Canonical\CanonicalClientDto;
use Coleza\Domain\Migration\Canonical\CanonicalDomainDto;
use Coleza\Domain\Migration\Canonical\CanonicalInvoiceDto;
use Coleza\Domain\Migration\Canonical\CanonicalPaymentDto;
use Coleza\Domain\Migration\Canonical\CanonicalProductDto;
use Coleza\Domain\Migration\Canonical\CanonicalServiceDto;
use Coleza\Domain\Migration\Canonical\CanonicalTicketDto;
use Coleza\Domain\Migration\Mapping\GenericMappingEngine;
use Coleza\Domain\Migration\Staging\DatabaseStagingRepository;
use Coleza\Domain\Migration\Staging\StagingAccountingReport;
use Coleza\Domain\Migration\Staging\StagingPipelineService;
use Coleza\Domain\Migration\Staging\StagingRecord;
use Coleza\Domain\Migration\Staging\StagingRecordStatus;
use Coleza\Domain\Migration\Validation\CanonicalValidationEngine;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class GenericStagingMappingValidationTest extends TestCase
{
    private Connection $db;
    private DatabaseStagingRepository $stagingRepo;
    private GenericMappingEngine $mappingEngine;
    private CanonicalValidationEngine $validationEngine;
    private StagingPipelineService $pipeline;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->stagingRepo = new DatabaseStagingRepository($this->db);
        $this->mappingEngine = new GenericMappingEngine();
        $this->validationEngine = new CanonicalValidationEngine();
        $this->pipeline = new StagingPipelineService(
            repository: $this->stagingRepo,
            mappingEngine: $this->mappingEngine,
            validationEngine: $this->validationEngine
        );
    }

    public function testStagingRecordLifecycleAndStateTransitions(): void
    {
        $raw = ['id' => 101, 'email' => 'user@example.com', 'firstname' => 'Alice'];
        $record = StagingRecord::createNew(
            batchId: 'BATCH-001',
            sourceSystem: 'whmcs',
            sourceEntityType: 'client',
            sourceEntityId: '101',
            rawPayload: $raw
        );

        $this->assertSame(StagingRecordStatus::STAGED, $record->getStatus());
        $this->assertFalse($record->getStatus()->isTerminal());

        // Transform & Validate
        $record->markTransformed('client', ['first_name' => 'Alice', 'email' => 'user@example.com']);
        $this->assertSame(StagingRecordStatus::TRANSFORMED, $record->getStatus());

        $record->markValidated();
        $this->assertSame(StagingRecordStatus::VALIDATED, $record->getStatus());

        // Migrate
        $record->markMigrated(targetEntityId: 505);
        $this->assertSame(StagingRecordStatus::MIGRATED, $record->getStatus());
        $this->assertSame(505, $record->getTargetEntityId());
        $this->assertTrue($record->getStatus()->isTerminal());

        // Quarantine flow
        $badRecord = StagingRecord::createNew('BATCH-001', 'whmcs', 'client', '102', ['id' => 102]);
        $badRecord->markQuarantined('Missing email', ['Email cannot be blank']);
        $this->assertSame(StagingRecordStatus::QUARANTINED, $badRecord->getStatus());
        $this->assertTrue($badRecord->getStatus()->isTerminal());
        $this->assertSame('Missing email', $badRecord->getQuarantineReason());
        $this->assertContains('Email cannot be blank', $badRecord->getValidationErrors());
    }

    public function testDatabaseStagingRepositoryPersistenceAndAccountingReport(): void
    {
        $record1 = StagingRecord::createNew('BATCH-A', 'whmcs', 'client', '1', ['id' => 1, 'email' => 'a@test.com']);
        $record2 = StagingRecord::createNew('BATCH-A', 'whmcs', 'client', '2', ['id' => 2, 'email' => 'b@test.com']);
        $record3 = StagingRecord::createNew('BATCH-A', 'whmcs', 'client', '3', ['id' => 3, 'email' => 'c@test.com']);

        $this->stagingRepo->save($record1);
        $this->stagingRepo->save($record2);
        $this->stagingRepo->save($record3);

        $this->assertNotNull($record1->getId());

        // Lookup
        $found = $this->stagingRepo->findBySourceEntity('BATCH-A', 'client', '1');
        $this->assertNotNull($found);
        $this->assertSame('1', $found->getSourceEntityId());

        // Update states
        $record1->markMigrated(1001);
        $this->stagingRepo->save($record1);

        $record2->markQuarantined('Duplicate account detected');
        $this->stagingRepo->save($record2);

        $record3->markSkippedUnsupported('Inactive legacy record');
        $this->stagingRepo->save($record3);

        // Terminal accounting verification
        $report = $this->stagingRepo->generateAccountingReport('BATCH-A');
        $this->assertInstanceOf(StagingAccountingReport::class, $report);
        $this->assertSame(3, $report->getTotalStagedRecords());
        $this->assertSame(1, $report->getMigratedCount());
        $this->assertSame(1, $report->getQuarantinedCount());
        $this->assertSame(1, $report->getSkippedCount());
        $this->assertSame(0, $report->getFailedCount());
        $this->assertSame(0, $report->getInFlightCount());

        // Zero Silent Data Loss check
        $this->assertSame(0, $report->getUnaccountedDiff());
        $this->assertTrue($report->isZeroSilentLossAchieved());
        $this->assertTrue($report->isFullyTerminal());
    }

    public function testGenericMappingEngineClientWithUnsupportedFieldRetention(): void
    {
        $rawClient = [
            'id' => '88',
            'firstname' => 'John',
            'lastname' => 'Doe',
            'email' => 'John.Doe@Example.COM',
            'companyname' => 'Acme Cloud Inc.',
            'phonenumber' => '+1234567890',
            'address1' => '123 Market St',
            'city' => 'Austin',
            'state' => 'TX',
            'postcode' => '78701',
            'country' => 'us',
            'currency' => 'USD',
            'status' => 'Active',
            'taxexempt' => 'on',
            'customfields' => ['VAT_NUMBER' => 'US123456'],
            'legacy_internal_flag' => 'VIP_ACCOUNT', // Unmapped field
            'old_crm_notes' => 'Migrated from 2021', // Unmapped field
        ];

        $clientDto = $this->mappingEngine->map('whmcs', 'client', $rawClient);
        $this->assertInstanceOf(CanonicalClientDto::class, $clientDto);

        $this->assertSame('88', $clientDto->getSourceId());
        $this->assertSame('John', $clientDto->getFirstName());
        $this->assertSame('Doe', $clientDto->getLastName());
        $this->assertSame('John Doe', $clientDto->getFullName());
        $this->assertSame('john.doe@example.com', $clientDto->getEmail()); // Normalized lowercase
        $this->assertSame('Acme Cloud Inc.', $clientDto->getCompanyName());
        $this->assertSame('US', $clientDto->getCountryCode()); // Normalized uppercase
        $this->assertSame('active', $clientDto->getStatus());
        $this->assertTrue($clientDto->isTaxExempt());
        $this->assertSame(['VAT_NUMBER' => 'US123456'], $clientDto->getCustomFields());

        // Zero Silent Loss: unmapped fields retained in metadata
        $meta = $clientDto->getMetadata();
        $this->assertArrayHasKey('unsupported_source_fields', $meta);
        $this->assertSame('VIP_ACCOUNT', $meta['unsupported_source_fields']['legacy_internal_flag']);
        $this->assertSame('Migrated from 2021', $meta['unsupported_source_fields']['old_crm_notes']);
    }

    public function testGenericMappingEngineProductsServicesDomains(): void
    {
        // Product
        $rawProduct = [
            'id' => '10',
            'name' => 'Pro cPanel Hosting',
            'type' => 'hostingaccount',
            'description' => 'Unlimited NVMe SSD',
            'monthly' => '14.99',
            'currency' => 'USD',
            'servertype' => 'cpanel',
            'configoption1' => 'package_pro_10g',
        ];
        $productDto = $this->mappingEngine->map('whmcs', 'product', $rawProduct);
        $this->assertInstanceOf(CanonicalProductDto::class, $productDto);
        $this->assertSame('10', $productDto->getSourceId());
        $this->assertSame('Pro cPanel Hosting', $productDto->getName());
        $this->assertSame('hosting', $productDto->getType());
        $this->assertSame(14.99, $productDto->getPrice());
        $this->assertSame('cpanel', $productDto->getModule());

        // Service
        $rawService = [
            'id' => '201',
            'userid' => '88',
            'packageid' => '10',
            'domain' => 'clientdomain.com',
            'username' => 'cpuser201',
            'domainstatus' => 'Active',
            'billingcycle' => 'Monthly',
            'amount' => '14.99',
            'currency' => 'USD',
            'regdate' => '2026-01-01',
            'nextduedate' => '2026-11-01',
        ];
        $serviceDto = $this->mappingEngine->map('whmcs', 'service', $rawService);
        $this->assertInstanceOf(CanonicalServiceDto::class, $serviceDto);
        $this->assertSame('201', $serviceDto->getSourceId());
        $this->assertSame('88', $serviceDto->getClientSourceId());
        $this->assertSame('10', $serviceDto->getProductSourceId());
        $this->assertSame('clientdomain.com', $serviceDto->getDomain());
        $this->assertSame('active', $serviceDto->getStatus());
        $this->assertSame(14.99, $serviceDto->getRecurringAmount());

        // Domain
        $rawDomain = [
            'id' => '301',
            'userid' => '88',
            'domain' => 'mycompany.org',
            'registrar' => 'enom',
            'status' => 'Active',
            'recurringamount' => '12.50',
            'registrationperiod' => '2',
            'registrationdate' => '2025-05-10',
            'expirydate' => '2027-05-10',
            'donotrenew' => '0',
            'idprotection' => '1',
        ];
        $domainDto = $this->mappingEngine->map('whmcs', 'domain', $rawDomain);
        $this->assertInstanceOf(CanonicalDomainDto::class, $domainDto);
        $this->assertSame('301', $domainDto->getSourceId());
        $this->assertSame('mycompany.org', $domainDto->getDomainName());
        $this->assertSame(12.50, $domainDto->getRecurringAmount());
        $this->assertSame(2, $domainDto->getRegistrationPeriodYears());
        $this->assertTrue($domainDto->isAutoRenew());
        $this->assertTrue($domainDto->hasIdProtection());
    }

    public function testGenericMappingEngineInvoicesPaymentsTickets(): void
    {
        // Invoice
        $rawInvoice = [
            'id' => '401',
            'userid' => '88',
            'invoicenum' => 'INV-401',
            'subtotal' => '100.00',
            'tax' => '20.00',
            'total' => '120.00',
            'status' => 'Paid',
            'date' => '2026-10-01',
            'datepaid' => '2026-10-02',
            'items' => [
                ['description' => 'Dedicated IP Addon', 'amount' => 100.00],
            ],
        ];
        $invDto = $this->mappingEngine->map('whmcs', 'invoice', $rawInvoice);
        $this->assertInstanceOf(CanonicalInvoiceDto::class, $invDto);
        $this->assertSame(100.00, $invDto->getSubtotal());
        $this->assertSame(20.00, $invDto->getTax());
        $this->assertSame(120.00, $invDto->getTotal());
        $this->assertSame('paid', $invDto->getStatus());

        // Payment
        $rawPayment = [
            'id' => '501',
            'userid' => '88',
            'invoiceid' => '401',
            'amountin' => '120.00',
            'fees' => '3.50',
            'transid' => 'ch_stripe_12345',
            'gateway' => 'stripe',
            'date' => '2026-10-02',
        ];
        $payDto = $this->mappingEngine->map('whmcs', 'payment', $rawPayment);
        $this->assertInstanceOf(CanonicalPaymentDto::class, $payDto);
        $this->assertSame(120.00, $payDto->getAmount());
        $this->assertSame(3.50, $payDto->getFeeAmount());
        $this->assertSame('ch_stripe_12345', $payDto->getTransactionId());
        $this->assertSame('stripe', $payDto->getGateway());

        // Ticket
        $rawTicket = [
            'id' => '601',
            'userid' => '88',
            'did' => 'Technical Support',
            'title' => 'Assistance with DNS records',
            'message' => 'Please update A record to point to new server.',
            'urgency' => 'High',
            'status' => 'Closed',
            'tid' => 'TICK-9923',
        ];
        $ticketDto = $this->mappingEngine->map('whmcs', 'ticket', $rawTicket);
        $this->assertInstanceOf(CanonicalTicketDto::class, $ticketDto);
        $this->assertSame('Assistance with DNS records', $ticketDto->getSubject());
        $this->assertSame('high', $ticketDto->getPriority());
        $this->assertSame('closed', $ticketDto->getStatus());
        $this->assertSame('TICK-9923', $ticketDto->getTicketMask());
    }

    public function testCanonicalValidationEngineDetectsCorruptOrInconsistentData(): void
    {
        // 1. Invalid Email Client
        $badClient = new CanonicalClientDto(
            sourceId: '1',
            sourceSystem: 'whmcs',
            firstName: 'Bad',
            lastName: 'Client',
            email: 'not-an-email'
        );
        $val1 = $this->validationEngine->validate($badClient);
        $this->assertFalse($val1->isValid());
        $this->assertStringContainsString('email [not-an-email] is invalid', $val1->getErrors()[0]);

        // 2. Invoice Math Inconsistency (total != subtotal + tax)
        $badInvoice = new CanonicalInvoiceDto(
            sourceId: '2',
            sourceSystem: 'whmcs',
            clientSourceId: '1',
            invoiceNumber: 'INV-001',
            subtotal: 100.00,
            tax: 20.00,
            total: 150.00 // Inconsistent! Expected 120.00
        );
        $val2 = $this->validationEngine->validate($badInvoice);
        $this->assertFalse($val2->isValid());
        $this->assertStringContainsString('Invoice math inconsistency', $val2->getErrors()[0]);

        // 3. Payment with zero amount
        $badPayment = new CanonicalPaymentDto(
            sourceId: '3',
            sourceSystem: 'whmcs',
            clientSourceId: '1',
            invoiceSourceId: '2',
            amount: 0.00
        );
        $val3 = $this->validationEngine->validate($badPayment);
        $this->assertFalse($val3->isValid());
        $this->assertStringContainsString('Payment amount must be greater than zero', $val3->getErrors()[0]);

        // 4. Invalid Domain syntax
        $badDomain = new CanonicalDomainDto(
            sourceId: '4',
            sourceSystem: 'whmcs',
            clientSourceId: '1',
            domainName: 'invaliddomainwithouttld'
        );
        $val4 = $this->validationEngine->validate($badDomain);
        $this->assertFalse($val4->isValid());
        $this->assertStringContainsString('Domain name [invaliddomainwithouttld] is invalid', $val4->getErrors()[0]);
    }

    public function testStagingPipelineBatchProcessingAndQuarantineAccounting(): void
    {
        $batchId = 'BATCH-MIXED-2026';

        // 3 Valid clients
        $this->pipeline->stageRawRecord($batchId, 'whmcs', 'client', '1', [
            'id' => 1, 'firstname' => 'Alice', 'lastname' => 'Smith', 'email' => 'alice@domain.com',
        ]);
        $this->pipeline->stageRawRecord($batchId, 'whmcs', 'client', '2', [
            'id' => 2, 'firstname' => 'Bob', 'lastname' => 'Jones', 'email' => 'bob@domain.com',
        ]);
        $this->pipeline->stageRawRecord($batchId, 'whmcs', 'client', '3', [
            'id' => 3, 'firstname' => 'Charlie', 'lastname' => 'Brown', 'email' => 'charlie@domain.com',
        ]);

        // 1 Client with invalid email -> should be QUARANTINED
        $this->pipeline->stageRawRecord($batchId, 'whmcs', 'client', '4', [
            'id' => 4, 'firstname' => 'Dave', 'lastname' => 'Defective', 'email' => 'corrupted-email-format',
        ]);

        // 1 Invoice with math error -> should be QUARANTINED
        $this->pipeline->stageRawRecord($batchId, 'whmcs', 'invoice', '101', [
            'id' => 101, 'userid' => '1', 'invoicenum' => 'INV-101', 'subtotal' => 100.0, 'tax' => 10.0, 'total' => 140.0,
        ]);

        // Execute batch staging pipeline
        $report = $this->pipeline->processBatchStaging($batchId);

        $this->assertSame(5, $report->getTotalStagedRecords());
        $this->assertSame(2, $report->getQuarantinedCount());
        $this->assertSame(0, $report->getFailedCount());

        // Zero Silent Loss: every record is accounted for!
        $this->assertSame(0, $report->getUnaccountedDiff());
        $this->assertTrue($report->isZeroSilentLossAchieved());

        // Verify quarantined records contain the specific reasons
        $quarantined = $this->stagingRepo->getBatchRecords($batchId, StagingRecordStatus::QUARANTINED);
        $this->assertCount(2, $quarantined);

        $quarantinedClient = $quarantined[0]->getSourceEntityType() === 'client' ? $quarantined[0] : $quarantined[1];
        $this->assertStringContainsString('is invalid or missing', $quarantinedClient->getValidationErrors()[0]);

        $quarantinedInvoice = $quarantined[0]->getSourceEntityType() === 'invoice' ? $quarantined[0] : $quarantined[1];
        $this->assertStringContainsString('Invoice math inconsistency', $quarantinedInvoice->getValidationErrors()[0]);
    }
}
