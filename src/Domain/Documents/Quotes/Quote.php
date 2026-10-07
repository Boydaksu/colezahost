<?php

declare(strict_types=1);

namespace Coleza\Domain\Documents\Quotes;

final class Quote
{
    /**
     * @param array<QuoteItem> $items
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private ?int $id,
        private string $quoteNumber,
        private ?string $originalQuoteNumber,
        private int $version,
        private int $userId,
        private ?int $organizationId,
        private QuoteStatus $status,
        private string $currencyCode,
        private int $subtotalMinor,
        private int $taxTotalMinor,
        private int $totalMinor,
        private string $validUntil,
        private ?string $sentAt = null,
        private ?string $acceptedAt = null,
        private ?string $acceptedIp = null,
        private ?string $rejectedAt = null,
        private ?string $rejectionReason = null,
        private ?int $convertedOrderId = null,
        private ?string $revisionNote = null,
        private ?string $notes = null,
        private array $items = [],
        private ?string $createdAt = null,
        private ?string $updatedAt = null,
        private array $metadata = []
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuoteNumber(): string
    {
        return $this->quoteNumber;
    }

    public function getOriginalQuoteNumber(): ?string
    {
        return $this->originalQuoteNumber ?? $this->quoteNumber;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getOrganizationId(): ?int
    {
        return $this->organizationId;
    }

    public function getStatus(): QuoteStatus
    {
        return $this->status;
    }

    public function getCurrencyCode(): string
    {
        return strtoupper($this->currencyCode);
    }

    public function getSubtotalMinor(): int
    {
        return $this->subtotalMinor;
    }

    public function getTaxTotalMinor(): int
    {
        return $this->taxTotalMinor;
    }

    public function getTotalMinor(): int
    {
        return $this->totalMinor;
    }

    public function getValidUntil(): string
    {
        return $this->validUntil;
    }

    public function getSentAt(): ?string
    {
        return $this->sentAt;
    }

    public function getAcceptedAt(): ?string
    {
        return $this->acceptedAt;
    }

    public function getAcceptedIp(): ?string
    {
        return $this->acceptedIp;
    }

    public function getRejectedAt(): ?string
    {
        return $this->rejectedAt;
    }

    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    public function getConvertedOrderId(): ?int
    {
        return $this->convertedOrderId;
    }

    public function getRevisionNote(): ?string
    {
        return $this->revisionNote;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    /**
     * @return array<QuoteItem>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function isExpired(?string $referenceDate = null): bool
    {
        $ref = $referenceDate !== null ? strtotime($referenceDate) : time();
        $validUntilTime = strtotime($this->validUntil . ' 23:59:59');

        return $validUntilTime !== false && $ref > $validUntilTime;
    }
}
