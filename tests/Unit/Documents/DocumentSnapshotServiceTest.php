<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Documents;

use Coleza\Domain\Documents\DocumentType;
use Coleza\Domain\Documents\Numbering\DocumentNumberGenerator;
use Coleza\Domain\Documents\Snapshots\DocumentSnapshotService;
use Coleza\Domain\Documents\Storage\PrivateDocumentStorage;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class DocumentSnapshotServiceTest extends TestCase
{
    private string $tempStorageDir;
    private PDO $pdo;
    private PrivateDocumentStorage $storage;
    private DocumentSnapshotService $snapshotService;
    private DocumentNumberGenerator $numberGenerator;

    protected function setUp(): void
    {
        $this->tempStorageDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'coleza_test_storage_' . bin2hex(random_bytes(6));
        mkdir($this->tempStorageDir, 0700, true);

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->storage = new PrivateDocumentStorage($this->tempStorageDir);
        $this->snapshotService = new DocumentSnapshotService($this->pdo, $this->storage);
        $this->numberGenerator = new DocumentNumberGenerator($this->pdo);
    }

    protected function tearDown(): void
    {
        $this->deleteRecursive($this->tempStorageDir);
    }

    public function testDocumentNumberGeneratorSequences(): void
    {
        $inv1 = $this->numberGenerator->generateNextNumber(DocumentType::INVOICE, 'org_1', 2026);
        $inv2 = $this->numberGenerator->generateNextNumber(DocumentType::INVOICE, 'org_1', 2026);
        $quo1 = $this->numberGenerator->generateNextNumber(DocumentType::QUOTE, 'org_1', 2026);
        $pro1 = $this->numberGenerator->generateNextNumber(DocumentType::PROFORMA, 'org_1', 2026);

        $this->assertSame('INV-2026-000001', $inv1);
        $this->assertSame('INV-2026-000002', $inv2);
        $this->assertSame('QUO-2026-000001', $quo1);
        $this->assertSame('PRO-2026-000001', $pro1);

        // Parse verification
        $parsed = $this->numberGenerator->parseNumber($inv2);
        $this->assertNotNull($parsed);
        $this->assertSame('INV', $parsed['prefix']);
        $this->assertSame(2026, $parsed['year']);
        $this->assertSame(2, $parsed['sequence']);
    }

    public function testStoragePreventsPathTraversalAndStoresSafely(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->storage->putDocument('../secret.txt', 'malicious');
    }

    public function testDocumentSnapshotCreationAndVersioning(): void
    {
        $docNumber = 'INV-2026-000088';
        $v1Payload = ['subtotal' => 100.0, 'tax' => 20.0, 'total' => 120.0];
        $v1Html = '<html><body><h1>Invoice v1</h1></body></html>';
        $v1Pdf = '%PDF-1.4 initial binary stream payload %%EOF';

        $snapshotV1 = $this->snapshotService->createSnapshot(
            type: DocumentType::INVOICE,
            documentNumber: $docNumber,
            entityId: '88',
            snapshotPayload: $v1Payload,
            renderedHtml: $v1Html,
            pdfBinary: $v1Pdf,
            tenantId: '1',
            userId: 1,
            reason: 'First issued invoice'
        );

        $this->assertSame(1, $snapshotV1->getVersion());
        $this->assertTrue($snapshotV1->isLatest());
        $this->assertSame(hash('sha256', $v1Pdf), $snapshotV1->getContentSha256());
        $this->assertTrue($this->snapshotService->verifySnapshotIntegrity($snapshotV1));

        // Create revision v2
        $v2Payload = ['subtotal' => 90.0, 'tax' => 18.0, 'total' => 108.0, 'discount' => 10.0];
        $v2Html = '<html><body><h1>Invoice v2 (Revised Discount)</h1></body></html>';
        $v2Pdf = '%PDF-1.4 revised binary stream payload %%EOF';

        $snapshotV2 = $this->snapshotService->createSnapshot(
            type: DocumentType::INVOICE,
            documentNumber: $docNumber,
            entityId: '88',
            snapshotPayload: $v2Payload,
            renderedHtml: $v2Html,
            pdfBinary: $v2Pdf,
            tenantId: '1',
            userId: 1,
            reason: 'Applied corporate discount correction'
        );

        $this->assertSame(2, $snapshotV2->getVersion());
        $this->assertTrue($snapshotV2->isLatest());
        $this->assertSame('Applied corporate discount correction', $snapshotV2->getChangeReason());
        $this->assertTrue($this->snapshotService->verifySnapshotIntegrity($snapshotV2));

        // Retrieve v1 from database and confirm it is no longer latest
        $retrievedV1 = $this->snapshotService->getSnapshotVersion(DocumentType::INVOICE, $docNumber, 1);
        $this->assertNotNull($retrievedV1);
        $this->assertFalse($retrievedV1->isLatest());
        $this->assertEquals(100.0, $retrievedV1->getSnapshotPayload()['subtotal']);

        // Check latest query returns v2
        $latest = $this->snapshotService->getLatestSnapshot(DocumentType::INVOICE, $docNumber);
        $this->assertNotNull($latest);
        $this->assertSame(2, $latest->getVersion());

        // Check all versions history
        $all = $this->snapshotService->getAllSnapshots(DocumentType::INVOICE, $docNumber);
        $this->assertCount(2, $all);
        $this->assertSame(1, $all[0]->getVersion());
        $this->assertSame(2, $all[1]->getVersion());
    }

    public function testSnapshotTamperDetection(): void
    {
        $docNumber = 'CTR-2026-000001';
        $pdfContent = '%PDF-1.4 Secure Contract Binary %%EOF';

        $snapshot = $this->snapshotService->createSnapshot(
            type: DocumentType::CONTRACT,
            documentNumber: $docNumber,
            entityId: '1',
            snapshotPayload: ['terms' => 'Standard SLA'],
            renderedHtml: '<div>Contract</div>',
            pdfBinary: $pdfContent
        );

        $this->assertTrue($this->snapshotService->verifySnapshotIntegrity($snapshot));

        // Tamper with the underlying storage file directly
        $storedPath = $snapshot->getPdfStoragePath();
        $this->assertNotNull($storedPath);
        $this->storage->putDocument($storedPath, '%PDF-1.4 TAMPERED CONTRACT BY ATTACKER %%EOF');

        // Integrity verification must fail
        $this->assertFalse($this->snapshotService->verifySnapshotIntegrity($snapshot));
    }

    private function deleteRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = scandir($dir);
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $this->deleteRecursive($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
