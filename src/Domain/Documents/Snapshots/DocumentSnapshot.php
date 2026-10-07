<?php

declare(strict_types=1);

namespace Coleza\Domain\Documents\Snapshots;

use Coleza\Domain\Documents\DocumentType;

final class DocumentSnapshot
{
    /**
     * @param array<string, mixed> $snapshotPayload Frozen entity data
     */
    public function __construct(
        private ?int $id,
        private DocumentType $documentType,
        private string $documentNumber,
        private int $version,
        private string $entityId,
        private array $snapshotPayload,
        private string $renderedHtml,
        private ?string $pdfStoragePath,
        private string $contentSha256,
        private string $createdAt,
        private ?int $createdByUserId = null,
        private ?string $changeReason = null,
        private bool $isLatest = true
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDocumentType(): DocumentType
    {
        return $this->documentType;
    }

    public function getDocumentNumber(): string
    {
        return $this->documentNumber;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getEntityId(): string
    {
        return $this->entityId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSnapshotPayload(): array
    {
        return $this->snapshotPayload;
    }

    public function getRenderedHtml(): string
    {
        return $this->renderedHtml;
    }

    public function getPdfStoragePath(): ?string
    {
        return $this->pdfStoragePath;
    }

    public function getContentSha256(): string
    {
        return $this->contentSha256;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    public function getCreatedByUserId(): ?int
    {
        return $this->createdByUserId;
    }

    public function getChangeReason(): ?string
    {
        return $this->changeReason;
    }

    public function isLatest(): bool
    {
        return $this->isLatest;
    }

    /**
     * Checks if given content matches this snapshot's cryptographic checksum.
     */
    public function verifyHash(string $content): bool
    {
        return hash_equals($this->contentSha256, hash('sha256', $content));
    }
}
