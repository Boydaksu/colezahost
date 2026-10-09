<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Migration;

use Coleza\Domain\Migration\Adoption\ProviderIdentityResolver;
use Coleza\Domain\Migration\Mapping\GenericMappingEngine;
use Coleza\Domain\Migration\Staging\DatabaseStagingRepository;
use Coleza\Domain\Migration\Staging\StagingPipelineService;
use Coleza\Domain\Migration\Staging\StagingRecordStatus;
use Coleza\Domain\Migration\Validation\CanonicalValidationEngine;
use Coleza\Domain\Migration\Whmcs\Support\UnsupportedDataAccountant;
use Coleza\Domain\Migration\Whmcs\Support\WhmcsSupportExtractor;
use Coleza\Domain\Migration\Whmcs\Support\WhmcsSupportMigrator;
use Coleza\Domain\Migration\Whmcs\WhmcsReadOnlyConnector;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class WhmcsSupportAndUnsupportedDataTest extends TestCase
{
    private Connection $whmcsDb;
    private Connection $targetDb;
    private WhmcsReadOnlyConnector $whmcsConnector;
    private DatabaseStagingRepository $stagingRepo;
    private StagingPipelineService $stagingPipeline;
    private GenericMappingEngine $mappingEngine;
    private CanonicalValidationEngine $validationEngine;
    private ProviderIdentityResolver $identityResolver;
    private WhmcsSupportExtractor $extractor;
    private UnsupportedDataAccountant $accountant;
    private WhmcsSupportMigrator $migrator;

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

        // 4. Resolver & Accountant
        $this->identityResolver = new ProviderIdentityResolver();
        $this->accountant = new UnsupportedDataAccountant();

        // 5. Support Extractor & Migrator
        $this->extractor = new WhmcsSupportExtractor($this->whmcsConnector);
        $this->migrator = new WhmcsSupportMigrator(
            targetDb: $this->targetDb,
            whmcs: $this->whmcsConnector,
            stagingRepo: $this->stagingRepo,
            stagingPipeline: $this->stagingPipeline,
            identityResolver: $this->identityResolver,
            extractor: $this->extractor,
            accountant: $this->accountant
        );

        // 6. Ensure target schema
        $this->migrator->ensureSupportTables();

        // 7. Create source WHMCS support schema
        $this->createSourceWhmcsTables();
    }

    private function createSourceWhmcsTables(): void
    {
        $this->whmcsDb->statement('CREATE TABLE tblticketdepartments (
            id INTEGER PRIMARY KEY,
            name VARCHAR(100),
            description TEXT,
            email VARCHAR(150),
            hidden INT,
            `order` INT
        )');

        $this->whmcsDb->statement('CREATE TABLE tbltickets (
            id INTEGER PRIMARY KEY,
            tid VARCHAR(50),
            did INT,
            userid INT,
            name VARCHAR(100),
            email VARCHAR(191),
            date DATETIME,
            title VARCHAR(255),
            message TEXT,
            status VARCHAR(30),
            urgency VARCHAR(30),
            lastreply DATETIME
        )');

        $this->whmcsDb->statement('CREATE TABLE tblticketreplies (
            id INTEGER PRIMARY KEY,
            tid INT,
            userid INT,
            name VARCHAR(100),
            email VARCHAR(191),
            date DATETIME,
            message TEXT,
            admin VARCHAR(50)
        )');

        $this->whmcsDb->statement('CREATE TABLE tblticketattachments (
            id INTEGER PRIMARY KEY,
            ticketid INT,
            replyid INT,
            filename VARCHAR(255)
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

    public function testExtractSupportDepartmentsTicketsRepliesAttachmentsCustomFields(): void
    {
        $this->whmcsDb->statement("INSERT INTO tblticketdepartments (id, name, description, email, hidden, `order`)
            VALUES (1, 'Technical Support', 'Technical inquiries', 'support@test.com', 0, 1)");

        $this->whmcsDb->statement("INSERT INTO tbltickets (id, tid, did, userid, date, title, message, status, urgency, lastreply)
            VALUES (101, 'ABC-1234', 1, 10, '2025-03-01 10:00:00', 'Server Connectivity Issue', 'Cannot reach my server', 'Open', 'High', '2025-03-01 11:00:00')");

        $this->whmcsDb->statement("INSERT INTO tblticketreplies (id, tid, userid, date, message, admin)
            VALUES (1, 101, 10, '2025-03-01 10:30:00', 'We are investigating the network', 'Admin Staff')");

        $this->whmcsDb->statement("INSERT INTO tblticketattachments (id, ticketid, replyid, filename)
            VALUES (1, 101, 0, 'ping_test.txt')");

        $this->whmcsDb->statement("INSERT INTO tblcustomfields (id, type, relid, fieldname)
            VALUES (1, 'support', 1, 'Server Hostname')");
        $this->whmcsDb->statement("INSERT INTO tblcustomfieldsvalues (id, fieldid, relid, value)
            VALUES (1, 1, 101, 'srv1.domain.com')");

        $depts = $this->extractor->extractDepartments();
        $this->assertCount(1, $depts);
        $this->assertSame('Technical Support', $depts[0]['name']);

        $tickets = $this->extractor->extractTickets();
        $this->assertCount(1, $tickets);
        $this->assertSame('ABC-1234', $tickets[0]['tid']);
        $this->assertCount(1, $tickets[0]['replies']);
        $this->assertSame('Admin Staff', $tickets[0]['replies'][0]['admin']);
        $this->assertCount(1, $tickets[0]['attachments']);
        $this->assertSame('ping_test.txt', $tickets[0]['attachments'][0]['filename']);
        $this->assertSame('srv1.domain.com', $tickets[0]['customfields']['Server Hostname']);
    }

    public function testMigrateDepartmentsAndDefaultFallback(): void
    {
        $this->whmcsDb->statement("INSERT INTO tblticketdepartments (id, name, description, email, hidden, `order`)
            VALUES (1, 'Billing Department', 'Billing questions', 'billing@test.com', 0, 2)");

        $stats = $this->migrator->migrateDepartments();
        $this->assertSame(1, $stats['total']);
        $this->assertSame(1, $stats['migrated']);

        // Check target database
        $dept = $this->targetDb->selectOne("SELECT * FROM support_departments WHERE name = 'Billing Department'");
        $this->assertNotNull($dept);
        $this->assertSame('billing@test.com', $dept['email']);
        $this->assertSame(1, (int)$dept['is_public']);
    }

    public function testMigrateTicketsWithRepliesAndAttachments(): void
    {
        $this->identityResolver->registerClientMapping('10', 510);

        $this->whmcsDb->statement("INSERT INTO tblticketdepartments (id, name, description, email, hidden, `order`)
            VALUES (1, 'General Support', 'General', 'support@test.com', 0, 1)");

        $this->whmcsDb->statement("INSERT INTO tbltickets (id, tid, did, userid, date, title, message, status, urgency, lastreply)
            VALUES (201, 'TICK-999', 1, 10, '2025-03-01 10:00:00', 'PHP 8.4 Upgrade Request', 'Please upgrade my PHP version', 'Customer-Reply', 'Medium', '2025-03-01 11:15:00')");

        $this->whmcsDb->statement("INSERT INTO tblticketreplies (id, tid, userid, date, message, admin)
            VALUES (501, 201, 0, '2025-03-01 11:15:00', 'PHP 8.4 is now activated on your account.', 'Support Agent')");

        $this->whmcsDb->statement("INSERT INTO tblticketattachments (id, ticketid, filename)
            VALUES (701, 201, 'phpinfo.png')");

        $batchId = 'batch-ticket-01';
        $this->migrator->migrateDepartments();
        $stats = $this->migrator->migrateTickets($batchId);

        $this->assertSame(1, $stats['total']);
        $this->assertSame(1, $stats['migrated']);
        $this->assertSame(0, $stats['quarantined']);
        $this->assertSame(1, $stats['replies_migrated']);
        $this->assertSame(1, $stats['attachments_migrated']);

        // Verify ticket in target database
        $ticket = $this->targetDb->selectOne("SELECT * FROM support_tickets WHERE ticket_number = 'TICK-999'");
        $this->assertNotNull($ticket);
        $this->assertSame(510, (int)$ticket['user_id']);
        $this->assertSame('open', $ticket['status']); // Customer-Reply maps to open
        $this->assertSame('medium', $ticket['priority']);
        $this->assertSame('PHP 8.4 Upgrade Request', $ticket['subject']);

        // Verify messages (initial + reply)
        $messages = $this->targetDb->select("SELECT * FROM support_ticket_messages WHERE ticket_id = ? ORDER BY id ASC", [(int)$ticket['id']]);
        $this->assertCount(2, $messages);
        $this->assertSame(0, (int)$messages[0]['is_staff']);
        $this->assertSame('Please upgrade my PHP version', $messages[0]['message']);
        $this->assertSame(1, (int)$messages[1]['is_staff']);
        $this->assertSame('PHP 8.4 is now activated on your account.', $messages[1]['message']);

        // Verify attachment
        $att = $this->targetDb->selectOne("SELECT * FROM support_ticket_attachments WHERE ticket_id = ?", [(int)$ticket['id']]);
        $this->assertNotNull($att);
        $this->assertSame('phpinfo.png', $att['original_filename']);
        $this->assertStringContainsString('migrated/tickets/', (string)$att['storage_key']);
        $this->assertSame(hash('sha256', 'phpinfo.png'), $att['sha256_hash']);

        // Verify staging record
        $records = $this->stagingRepo->getBatchRecords($batchId);
        $this->assertCount(1, $records);
        $this->assertSame(StagingRecordStatus::MIGRATED, $records[0]->getStatus());
    }

    public function testUnsupportedDataAccountingZeroFieldLoss(): void
    {
        $this->identityResolver->registerClientMapping('10', 510);

        // Ticket with legacy WHMCS unmapped source fields
        $rawTicket = [
            'id' => 301,
            'tid' => 'LEGACY-001',
            'did' => 1,
            'userid' => 10,
            'title' => 'Legacy Inquiry',
            'message' => 'Legacy message',
            'status' => 'Closed',
            'urgency' => 'Low',
            'date' => '2024-01-01 00:00:00',
            // Obsolete/unmapped properties:
            'cpanel_theme_preference' => 'paper_lantern',
            'whmcs_legacy_flag' => 4,
            'obsolete_tag' => 'old_system',
        ];

        // Insert into WHMCS DB
        $this->whmcsDb->statement("INSERT INTO tbltickets (id, tid, did, userid, date, title, message, status, urgency)
            VALUES (301, 'LEGACY-001', 1, 10, '2024-01-01 00:00:00', 'Legacy Inquiry', 'Legacy message', 'Closed', 'Low')");

        $batchId = 'batch-unsupported-audit-01';
        $this->migrator->migrateDepartments();
        $this->migrator->migrateTickets($batchId);

        $report = $this->accountant->generateReport($batchId);

        // Invariant: dropped fields must be exactly 0
        $this->assertSame(0, $report->getTotalDroppedFields());
        $this->assertTrue($report->isZeroLossAchieved());

        // Check target ticket metadata preserves unsupported fields
        $ticket = $this->targetDb->selectOne("SELECT metadata_json FROM support_tickets WHERE ticket_number = 'LEGACY-001'");
        $this->assertNotNull($ticket);
        $meta = json_decode((string)$ticket['metadata_json'], true);
        $this->assertIsArray($meta);
        $this->assertArrayHasKey('unsupported_source_fields', $meta);
    }

    public function testQuarantineUnresolvedClientTickets(): void
    {
        // Client 999 is NOT in identityResolver
        $this->whmcsDb->statement("INSERT INTO tbltickets (id, tid, did, userid, date, title, message, status)
            VALUES (401, 'ORPHAN-01', 1, 999, '2025-01-01 10:00:00', 'Orphan Ticket', 'No user', 'Open')");

        $batchId = 'batch-orphan-ticket-01';
        $this->migrator->migrateDepartments();
        $stats = $this->migrator->migrateTickets($batchId);

        $this->assertSame(1, $stats['total']);
        $this->assertSame(0, $stats['migrated']);
        $this->assertSame(1, $stats['quarantined']);

        $quarantined = $this->stagingRepo->getBatchRecords($batchId, StagingRecordStatus::QUARANTINED);
        $this->assertCount(1, $quarantined);
        $this->assertStringContainsString('unmigrated client [999]', (string)$quarantined[0]->getQuarantineReason());

        $report = $this->stagingRepo->generateAccountingReport($batchId);
        $this->assertSame(0, $report->getUnaccountedDiff());
        $this->assertTrue($report->isZeroSilentLossAchieved());
    }

    public function testEndToEndSupportMigrationCertification(): void
    {
        $this->identityResolver->registerClientMapping('10', 510);
        $this->identityResolver->registerClientMapping('20', 520);

        $this->whmcsDb->statement("INSERT INTO tblticketdepartments (id, name, description, email, hidden, `order`)
            VALUES (1, 'Customer Care', 'Care team', 'care@test.com', 0, 1)");

        // 2 valid tickets
        $this->whmcsDb->statement("INSERT INTO tbltickets (id, tid, did, userid, date, title, message, status, urgency)
            VALUES (501, 'CARE-100', 1, 10, '2025-03-01 10:00:00', 'Help with billing', 'Billing question', 'Open', 'Low')");
        $this->whmcsDb->statement("INSERT INTO tbltickets (id, tid, did, userid, date, title, message, status, urgency)
            VALUES (502, 'CARE-200', 1, 20, '2025-03-01 11:00:00', 'Domain transfer help', 'Transfer question', 'Answered', 'Medium')");

        $batchId = 'e2e-support-batch-01';
        $result = $this->migrator->migrateAndAudit($batchId);

        // Certifications
        $this->assertTrue($result->isCertified());
        $this->assertTrue($result->isZeroSilentLossAchieved());
        $this->assertTrue($result->isZeroFieldLossAchieved());
        $this->assertTrue($result->isFullyTerminal());

        $this->assertSame(0, $result->getStagingReport()->getUnaccountedDiff());
        $this->assertSame(2, $result->getTicketStats()['migrated']);
        $this->assertSame(0, $result->getTicketStats()['quarantined']);
        $this->assertSame(0, $result->getUnsupportedDataReport()->getTotalDroppedFields());
    }
}
