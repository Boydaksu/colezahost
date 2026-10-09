<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Documents;

use Coleza\Domain\Documents\DocumentType;
use Coleza\Domain\Documents\Numbering\DocumentNumberGenerator;
use PDO;
use PHPUnit\Framework\TestCase;

final class DocumentNumberPersistenceRegressionTest extends TestCase
{
    public function testSequenceRespectsTheCallersTransactionAndRollback(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $generator = new DocumentNumberGenerator($pdo);
        $pdo->beginTransaction();
        self::assertSame('QUO-2026-000001', $generator->generateNextNumber(DocumentType::QUOTE, 7, 2026));
        self::assertSame('QUO-2026-000002', $generator->generateNextNumber(DocumentType::QUOTE, 7, 2026));
        self::assertTrue($pdo->inTransaction());
        $pdo->rollBack();
        self::assertSame('QUO-2026-000001', $generator->generateNextNumber(DocumentType::QUOTE, 7, 2026));
        self::assertFalse($pdo->inTransaction());
    }

    public function testLegacyCountersAndCompositeScopesArePreserved(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $generator = new DocumentNumberGenerator($pdo);
        $pdo->exec("INSERT INTO document_sequences (tenant_id, document_type, year, last_number) VALUES ('7', 'quote', 2026, 40)");
        $generator = new DocumentNumberGenerator($pdo);
        self::assertSame('QUO-2026-000041', $generator->generateNextNumber(DocumentType::QUOTE, 7, 2026));
        self::assertSame('QUO-2027-000001', $generator->generateNextNumber(DocumentType::QUOTE, 7, 2027));
        self::assertSame('QUO-2026-000001', $generator->generateNextNumber(DocumentType::QUOTE, 8, 2026));
        self::assertSame('PRO-2026-000001', $generator->generateNextNumber(DocumentType::PROFORMA, 7, 2026));
    }
}
