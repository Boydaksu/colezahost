<?php

declare(strict_types=1);

namespace Coleza\Domain\Documents\Snapshots;

use Coleza\Domain\Documents\DocumentType;
use Coleza\Domain\Documents\Storage\DocumentStorageInterface;
use Coleza\Domain\Documents\Storage\PrivateDocumentStorage;
use PDO;
use RuntimeException;

final class DocumentSnapshotService
{
    /**
     * @var array<string, array<int, DocumentSnapshot>> In-memory cache when PDO is null
     */
    private array $memorySnapshots = [];

    public function __construct(
        private ?PDO $pdo = null,
        private ?DocumentStorageInterface $storage = null
    ) {
        $this->storage ??= new PrivateDocumentStorage();
        if ($this->pdo !== null) {
            $this->ensureSchema();
        }
    }

    /**
     * Creates a new immutable snapshot of a document, versioning it automatically.
     *
     * @param DocumentType $type
     * @param string $documentNumber
     * @param string $entityId
     * @param array<string, mixed> $snapshotPayload
     * @param string $renderedHtml
     * @param string $pdfBinary
     * @param string|int $tenantId
     * @param int|null $userId
     * @param string|null $reason
     * @return DocumentSnapshot
     */
    public function createSnapshot(
        DocumentType $type,
        string $documentNumber,
        string $entityId,
        array $snapshotPayload,
        string $renderedHtml,
        string $pdfBinary,
        string|int $tenantId = '1',
        ?int $userId = null,
        ?string $reason = 'Initial document creation'
    ): DocumentSnapshot {
        $currentLatest = $this->getLatestSnapshot($type, $documentNumber);
        $nextVersion = $currentLatest !== null ? $currentLatest->getVersion() + 1 : 1;

        $contentSha256 = hash('sha256', $pdfBinary);
        $pdfPath = $this->storage->resolveStoragePath($tenantId, $type, $documentNumber, $nextVersion, 'pdf');

        $stored = $this->storage->putDocument($pdfPath, $pdfBinary);
        if (!$stored) {
            throw new RuntimeException("Failed to store document PDF in private storage: {$pdfPath}");
        }

        $now = date('c');

        if ($this->pdo === null) {
            $key = "{$type->value}:{$documentNumber}";
            if (isset($this->memorySnapshots[$key])) {
                foreach ($this->memorySnapshots[$key] as $idx => $s) {
                    $this->memorySnapshots[$key][$idx] = new DocumentSnapshot(
                        id: $s->getId(),
                        documentType: $s->getDocumentType(),
                        documentNumber: $s->getDocumentNumber(),
                        version: $s->getVersion(),
                        entityId: $s->getEntityId(),
                        snapshotPayload: $s->getSnapshotPayload(),
                        renderedHtml: $s->getRenderedHtml(),
                        pdfStoragePath: $s->getPdfStoragePath(),
                        contentSha256: $s->getContentSha256(),
                        createdAt: $s->getCreatedAt(),
                        createdByUserId: $s->getCreatedByUserId(),
                        changeReason: $s->getChangeReason(),
                        isLatest: false
                    );
                }
            }

            $id = count($this->memorySnapshots[$key] ?? []) + 1;
            $snapshot = new DocumentSnapshot(
                id: $id,
                documentType: $type,
                documentNumber: $documentNumber,
                version: $nextVersion,
                entityId: $entityId,
                snapshotPayload: $snapshotPayload,
                renderedHtml: $renderedHtml,
                pdfStoragePath: $pdfPath,
                contentSha256: $contentSha256,
                createdAt: $now,
                createdByUserId: $userId,
                changeReason: $reason,
                isLatest: true
            );

            $this->memorySnapshots[$key][] = $snapshot;
            return $snapshot;
        }

        // With PDO
        $this->pdo->beginTransaction();
        try {
            // Demote existing versions
            $demote = $this->pdo->prepare(
                'UPDATE document_snapshots SET is_latest = 0 WHERE document_type = :doc_type AND document_number = :doc_number'
            );
            $demote->execute([
                ':doc_type' => $type->value,
                ':doc_number' => $documentNumber,
            ]);

            $stmt = $this->pdo->prepare(
                'INSERT INTO document_snapshots (
                    document_type, document_number, version, entity_id, snapshot_payload,
                    rendered_html, pdf_storage_path, content_sha256, created_at, created_by_user_id,
                    change_reason, is_latest
                ) VALUES (
                    :doc_type, :doc_number, :version, :entity_id, :payload,
                    :html, :pdf_path, :sha256, :created_at, :user_id,
                    :reason, 1
                )'
            );
            $stmt->execute([
                ':doc_type' => $type->value,
                ':doc_number' => $documentNumber,
                ':version' => $nextVersion,
                ':entity_id' => $entityId,
                ':payload' => json_encode($snapshotPayload, JSON_THROW_ON_ERROR),
                ':html' => $renderedHtml,
                ':pdf_path' => $pdfPath,
                ':sha256' => $contentSha256,
                ':created_at' => $now,
                ':user_id' => $userId,
                ':reason' => $reason,
            ]);

            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();

            return new DocumentSnapshot(
                id: $id,
                documentType: $type,
                documentNumber: $documentNumber,
                version: $nextVersion,
                entityId: $entityId,
                snapshotPayload: $snapshotPayload,
                renderedHtml: $renderedHtml,
                pdfStoragePath: $pdfPath,
                contentSha256: $contentSha256,
                createdAt: $now,
                createdByUserId: $userId,
                changeReason: $reason,
                isLatest: true
            );
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function getLatestSnapshot(DocumentType $type, string $documentNumber): ?DocumentSnapshot
    {
        if ($this->pdo === null) {
            $key = "{$type->value}:{$documentNumber}";
            $list = $this->memorySnapshots[$key] ?? [];
            if (empty($list)) {
                return null;
            }

            foreach ($list as $s) {
                if ($s->isLatest()) {
                    return $s;
                }
            }

            return end($list) ?: null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM document_snapshots WHERE document_type = :doc_type AND document_number = :doc_number AND is_latest = 1 LIMIT 1'
        );
        $stmt->execute([
            ':doc_type' => $type->value,
            ':doc_number' => $documentNumber,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapRow($row) : null;
    }

    public function getSnapshotVersion(DocumentType $type, string $documentNumber, int $version): ?DocumentSnapshot
    {
        if ($this->pdo === null) {
            $key = "{$type->value}:{$documentNumber}";
            $list = $this->memorySnapshots[$key] ?? [];
            foreach ($list as $s) {
                if ($s->getVersion() === $version) {
                    return $s;
                }
            }
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM document_snapshots WHERE document_type = :doc_type AND document_number = :doc_number AND version = :version LIMIT 1'
        );
        $stmt->execute([
            ':doc_type' => $type->value,
            ':doc_number' => $documentNumber,
            ':version' => $version,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapRow($row) : null;
    }

    /**
     * @return array<DocumentSnapshot>
     */
    public function getAllSnapshots(DocumentType $type, string $documentNumber): array
    {
        if ($this->pdo === null) {
            $key = "{$type->value}:{$documentNumber}";
            return $this->memorySnapshots[$key] ?? [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM document_snapshots WHERE document_type = :doc_type AND document_number = :doc_number ORDER BY version ASC'
        );
        $stmt->execute([
            ':doc_type' => $type->value,
            ':doc_number' => $documentNumber,
        ]);

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = $this->mapRow($row);
        }

        return $results;
    }

    /**
     * Verifies that the physical storage file exists and matches the cryptographic contentSha256 hash.
     */
    public function verifySnapshotIntegrity(DocumentSnapshot $snapshot): bool
    {
        $path = $snapshot->getPdfStoragePath();
        if ($path === null) {
            return false;
        }

        return $this->storage->verifyChecksum($path, $snapshot->getContentSha256());
    }

    public function getStorage(): DocumentStorageInterface
    {
        return $this->storage;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): DocumentSnapshot
    {
        return new DocumentSnapshot(
            id: (int) $row['id'],
            documentType: DocumentType::from((string) $row['document_type']),
            documentNumber: (string) $row['document_number'],
            version: (int) $row['version'],
            entityId: (string) $row['entity_id'],
            snapshotPayload: json_decode((string) $row['snapshot_payload'], true, 512, JSON_THROW_ON_ERROR),
            renderedHtml: (string) $row['rendered_html'],
            pdfStoragePath: $row['pdf_storage_path'] !== null ? (string) $row['pdf_storage_path'] : null,
            contentSha256: (string) $row['content_sha256'],
            createdAt: (string) $row['created_at'],
            createdByUserId: $row['created_by_user_id'] !== null ? (int) $row['created_by_user_id'] : null,
            changeReason: $row['change_reason'] !== null ? (string) $row['change_reason'] : null,
            isLatest: ((int) $row['is_latest']) === 1
        );
    }

    private function ensureSchema(): void
    {
        if ($this->pdo === null) {
            return;
        }

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS document_snapshots (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                document_type VARCHAR(32) NOT NULL,
                document_number VARCHAR(64) NOT NULL,
                version INTEGER NOT NULL DEFAULT 1,
                entity_id VARCHAR(64) NOT NULL,
                snapshot_payload TEXT NOT NULL,
                rendered_html TEXT NOT NULL,
                pdf_storage_path VARCHAR(255),
                content_sha256 VARCHAR(64) NOT NULL,
                created_at VARCHAR(64) NOT NULL,
                created_by_user_id INTEGER,
                change_reason TEXT,
                is_latest INTEGER NOT NULL DEFAULT 1,
                UNIQUE(document_type, document_number, version)
            )'
        );
    }
}
