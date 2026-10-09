<?php

declare(strict_types=1);

namespace Coleza\Tests\MariaDb;

use Coleza\Domain\Documents\DocumentType;
use Coleza\Domain\Documents\Numbering\DocumentNumberGenerator;
use Coleza\Domain\Documents\Proforma\ProformaService;
use Coleza\Domain\Documents\Quotes\QuoteService;
use Coleza\Domain\Notifications\Announcements\AnnouncementService;
use Coleza\Tests\Support\MariaDbTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class DocumentPersistenceTest extends MariaDbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->root->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function testQuotePersistsItemsAndTurkishContentAcrossConnections(): void
    {
        $service = new QuoteService($this->root);
        $quote = $service->createQuote(7, [['description' => 'Özel barındırma', 'quantity' => 2, 'unit_amount_minor' => 10000, 'tax_rate' => 20]], notes: 'İstanbul');
        $pdo = new \PDO((string) getenv('COLEZA_TEST_MARIADB_DSN'), getenv('COLEZA_TEST_MARIADB_USER'), getenv('COLEZA_TEST_MARIADB_PASSWORD'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('USE `' . $this->database . '`');
        $loaded = (new QuoteService($pdo))->find($quote->getId());
        self::assertSame(24000, $loaded->getTotalMinor());
        self::assertSame('Özel barındırma', $loaded->getItems()[0]->getDescription());
        self::assertSame('İstanbul', $loaded->getNotes());
    }

    public function testProformaPersistsItemsAndAmounts(): void
    {
        $service = new ProformaService($this->root);
        $invoice = $service->createProforma(7, [['description' => 'Yıllık hizmet', 'unit_amount_minor' => 10000, 'tax_rate' => 20]]);
        $loaded = (new ProformaService($this->root))->find($invoice->getId());
        self::assertSame(12000, $loaded->getTotalMinor());
        self::assertSame('Yıllık hizmet', $loaded->getItems()[0]->getDescription());
        self::assertSame(0, $loaded->getPaidAmountMinor());
    }

    public function testAnnouncementPersistsAndPublishes(): void
    {
        $service = new AnnouncementService($this->root);
        $announcement = $service->createAnnouncement('Bakım duyurusu', 'İşlem açıklaması');
        $service->publishAnnouncement($announcement->getId());
        $loaded = (new AnnouncementService($this->root))->find($announcement->getId());
        self::assertTrue($loaded->isPublished());
        self::assertSame('İşlem açıklaması', $loaded->getContent());
    }

    public function testParallelDocumentNumbersAreUniqueAndPreserveLegacyCounter(): void
    {
        $generator = new DocumentNumberGenerator($this->root);
        $this->db->statement('INSERT INTO document_sequences (tenant_id, document_type, year, last_number) VALUES (?, ?, ?, ?)', ['7', 'quote', 2026, 40]);
        $results = $this->runParallel('documentNumbers', 7);
        $numbers = array_merge(...array_column($results, 'numbers'));
        self::assertCount(256, $numbers);
        self::assertCount(256, array_unique($numbers));
        sort($numbers);
        self::assertSame('QUO-2026-000041', $numbers[0]);
        self::assertSame('QUO-2026-000296', $numbers[255]);
        self::assertSame('QUO-2026-000001', $generator->generateNextNumber(DocumentType::QUOTE, 8, 2026));
        self::assertSame('PRO-2026-000001', $generator->generateNextNumber(DocumentType::PROFORMA, 7, 2026));
    }

    public static function schemaServices(): array
    {
        return [[DocumentNumberGenerator::class], [QuoteService::class], [ProformaService::class], [AnnouncementService::class]];
    }

    public function testDocumentMigrationPreservesExistingRowsAndCanBeRepeated(): void
    {
        $quote = (new QuoteService($this->root))->createQuote(7, [['description' => 'Önceki teklif', 'unit_amount_minor' => 9000]]);
        $proforma = (new ProformaService($this->root))->createProforma(7, [['description' => 'Önceki proforma', 'unit_amount_minor' => 8000]]);
        (new AnnouncementService($this->root))->createAnnouncement('Eski duyuru', 'Korunacak içerik');
        $tables = ['quotes', 'quote_items', 'proforma_invoices', 'proforma_items', 'announcements', 'document_sequences'];
        $before = [];
        foreach ($tables as $table) { $before[$table] = $this->db->select("SELECT * FROM {$table} ORDER BY id"); }
        $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_10_09_000004_documents_announcements.php';
        $migration->up($this->db);
        $migration->up($this->db);
        foreach ($tables as $table) { self::assertSame($before[$table], $this->db->select("SELECT * FROM {$table} ORDER BY id")); }
        self::assertSame($quote->getQuoteNumber(), (new QuoteService($this->root))->find($quote->getId())->getQuoteNumber());
        self::assertSame($proforma->getProformaNumber(), (new ProformaService($this->root))->find($proforma->getId())->getProformaNumber());
    }

    public function testDocumentMigrationCompletesAPartialSchema(): void
    {
        new QuoteService($this->root);
        $this->root->exec('DROP TABLE quote_items');
        $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_10_09_000004_documents_announcements.php';
        $migration->up($this->db);
        $quote = (new QuoteService($this->root))->createQuote(7, [['description' => 'Tamamlanan kurulum', 'unit_amount_minor' => 100]]);
        self::assertCount(1, $quote->getItems());
        self::assertSame(100, (new QuoteService($this->root))->find($quote->getId())->getTotalMinor());
    }

    public static function documentServices(): array
    {
        return [[QuoteService::class, 'createQuote', 'quotes', 'quote_items'], [ProformaService::class, 'createProforma', 'proforma_invoices', 'proforma_items']];
    }

    #[DataProvider('documentServices')]
    public function testItemWriteFailureDoesNotLeaveAParentDocument(string $class, string $method, string $parent, string $items): void
    {
        $service = new $class($this->root);
        $this->root->exec("CREATE TRIGGER reject_item BEFORE INSERT ON {$items} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected item write failure'");
        try {
            $service->$method(7, [['description' => 'Failing item', 'unit_amount_minor' => 100]]);
            self::fail('Injected storage failure must reach the caller.');
        } catch (\PDOException $error) {
            self::assertStringContainsString('Injected item write failure', $error->getMessage());
        }
        self::assertFalse($this->root->inTransaction());
        self::assertSame(0, (int) $this->db->selectOne("SELECT COUNT(*) n FROM {$parent}")['n']);
        self::assertSame(0, (int) $this->db->selectOne("SELECT COUNT(*) n FROM {$items}")['n']);
        $this->root->exec('DROP TRIGGER reject_item');
        self::assertGreaterThan(0, $service->$method(7, [['description' => 'Retry', 'unit_amount_minor' => 100]])->getId());
    }

    #[DataProvider('schemaServices')]
    public function testConstructorCannotCommitAnExistingTransaction(string $class): void
    {
        $this->root->beginTransaction();
        try {
            new $class($this->root);
            self::fail('Schema initialization must reject MySQL application transactions.');
        } catch (\LogicException $error) {
            self::assertTrue($this->root->inTransaction());
        } finally {
            if ($this->root->inTransaction()) { $this->root->rollBack(); }
        }
        self::assertSame([], $this->db->select("SHOW TABLES"));
    }
}
