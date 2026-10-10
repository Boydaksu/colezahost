<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;

use Coleza\Domain\Documents\Quotes\QuoteService;
use Coleza\Domain\Documents\Proforma\ProformaService;
use Coleza\Domain\Health\SystemDoctorService;
use Coleza\Domain\Health\HealthStatus;
use Coleza\Domain\Installer\DatabaseSetupService;
use Coleza\Tests\Support\MariaDbTestCase;

final class InstallationAcceptanceTest extends MariaDbTestCase
{
    public static function parallelDocuments(): array
    {
        return [['parallelQuotes', QuoteService::class, 'quotes'], ['parallelProformas', ProformaService::class, 'proforma_invoices']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('parallelDocuments')]
    public function testConcurrentDocumentsFromFourOrganizationsAreUnique(string $action, string $class, string $table): void
    {
        new $class($this->root);
        $results = $this->runParallel($action, 7);
        $numbers = array_merge(...array_column($results, 'numbers'));
        self::assertCount(64, $numbers);
        self::assertCount(64, array_unique($numbers));
        self::assertSame(64, (int) $this->db->selectOne("SELECT COUNT(*) n FROM {$table}")['n']);
        self::assertSame(4, (int) $this->db->selectOne("SELECT COUNT(DISTINCT organization_id) n FROM {$table}")['n']);
    }
    public function testDifferentOrganizationsCanCreateDocumentsWithUnambiguousNumbers(): void
    {
        foreach ([[QuoteService::class, 'createQuote', 'getQuoteNumber'], [ProformaService::class, 'createProforma', 'getProformaNumber']] as [$class, $create, $number]) {
            $service = new $class($this->root);
            $items = [['description' => 'Hosting', 'unit_amount_minor' => 100]];
            $one = $service->$create(7, $items, organizationId: 7);
            $two = $service->$create(8, $items, organizationId: 8);
            self::assertNotSame($one->$number(), $two->$number());
            self::assertSame(7, $service->find($one->getId())->getOrganizationId());
            self::assertSame(8, $service->find($two->getId())->getOrganizationId());
        }
    }

    public function testDoctorDoesNotCreateTablesOrCommitAnApplicationTransaction(): void
    {
        $this->root->beginTransaction();
        (new SystemDoctorService($this->db))->diagnoseAll();
        self::assertTrue($this->root->inTransaction());
        $this->root->rollBack();
        self::assertSame([], $this->db->select('SHOW TABLES'));
    }

    public function testDoctorReadsInstalledCronAndActualQueue(): void
    {
        (new DatabaseSetupService())->initializeCoreSchema($this->db);
        (new \Coleza\Foundation\Queue\DatabaseQueue($this->db))->ensureTables();
        $this->db->statement("INSERT INTO cron_runs (run_at, status) VALUES (?, 'success')", [date('Y-m-d H:i:s')]);
        $this->db->statement("INSERT INTO failed_jobs (queue, payload, exception) VALUES ('default', 'test', 'failure')");
        $doctor = new SystemDoctorService($this->db);
        self::assertSame(HealthStatus::HEALTHY, $doctor->checkCron()->getStatus());
        self::assertSame(1, $doctor->checkQueue()->getMetrics()['failed']);
        self::assertSame(HealthStatus::WARNING, $doctor->checkQueue()->getStatus());
    }

    public function testApplicationSchemaInstallsMigrationsAndRepeatsWithoutDataLoss(): void
    {
        $setup = new DatabaseSetupService();
        $setup->initializeApplicationSchema($this->db);
        self::assertNotEmpty($this->db->select('SELECT * FROM migrations'));
        foreach (['orders', 'order_items', 'invoices', 'invoice_items', 'products', 'organizations', 'organization_invitations', 'installed_modules', 'jobs', 'failed_jobs'] as $table) {
            self::assertSame([], $this->db->select("SELECT * FROM {$table}"));
        }
        $quote = (new QuoteService($this->root))->createQuote(7, [['description' => 'Korunan veri', 'unit_amount_minor' => 100]]);
        $before = $this->db->select('SELECT * FROM quotes');
        $setup->initializeApplicationSchema($this->db);
        self::assertSame($before, $this->db->select('SELECT * FROM quotes'));
        self::assertSame($quote->getId(), (new QuoteService($this->root))->find($quote->getId())->getId());
    }

    public function testLegacyDocumentAndCounterSurviveGlobalNumberMigration(): void
    {
        $service = new QuoteService($this->root);
        new ProformaService($this->root);
        $old = $service->createQuote(7, [['description' => 'Old', 'unit_amount_minor' => 100]], organizationId: 7);
        $year = (int) date('Y');
        $this->db->statement('UPDATE quotes SET quote_number = ?, original_quote_number = ? WHERE id = ?', ["QUO-{$year}-000700", "QUO-{$year}-000700", $old->getId()]);
        $this->db->statement("INSERT INTO document_sequences (tenant_id, document_type, year, last_number) VALUES ('7', 'quote', ?, 800)", [$year]);
        $before = $this->db->select('SELECT * FROM quotes');
        $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_10_10_000005_global_document_counters.php';
        $migration->up($this->db);
        $migration->up($this->db);
        self::assertSame($before, $this->db->select('SELECT * FROM quotes'));
        self::assertSame(800, (int) $this->db->selectOne("SELECT last_number FROM document_sequences WHERE tenant_id = '7'")['last_number']);
        $new = $service->createQuote(8, [['description' => 'New', 'unit_amount_minor' => 100]], organizationId: 8);
        self::assertSame("QUO-{$year}-000801", $new->getQuoteNumber());
    }

    public function testUnsupportedPrefixIsRejectedBeforeAnyTableIsCreated(): void
    {
        try {
            (new DatabaseSetupService())->initializeApplicationSchema($this->db, 'tenant_');
            self::fail('Unsupported prefix must be rejected before schema mutation.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('prefix', $error->getMessage());
        }
        self::assertSame([], $this->db->select('SHOW TABLES'));
    }

    public function testLegacyDoctorCronHistoryIsPreservedWithoutInventingSuccess(): void
    {
        $this->db->statement('CREATE TABLE cron_runs (id INT AUTO_INCREMENT PRIMARY KEY, ran_at TIMESTAMP NULL, tasks_executed INT, output_summary TEXT)');
        $this->db->statement('INSERT INTO cron_runs (ran_at, tasks_executed, output_summary) VALUES (?, 4, ?)', ['2025-01-01 12:00:00', 'Old record']);
        (new DatabaseSetupService())->initializeApplicationSchema($this->db);
        $row = $this->db->selectOne('SELECT * FROM cron_runs');
        self::assertSame('2025-01-01 12:00:00', $row['ran_at']);
        self::assertSame($row['ran_at'], $row['run_at']);
        self::assertSame('Old record', $row['output_summary']);
        self::assertSame('unknown', $row['status']);
        self::assertSame(HealthStatus::CRITICAL, (new SystemDoctorService($this->db))->checkCron()->getStatus());
    }

    public function testFailedCronIsNotHealthyEvenWhenRecent(): void
    {
        (new DatabaseSetupService())->initializeCoreSchema($this->db);
        $this->db->statement("INSERT INTO cron_runs (run_at, status) VALUES (?, 'failed')", [date('Y-m-d H:i:s')]);
        self::assertSame(HealthStatus::CRITICAL, (new SystemDoctorService($this->db))->checkCron()->getStatus());
    }
}
