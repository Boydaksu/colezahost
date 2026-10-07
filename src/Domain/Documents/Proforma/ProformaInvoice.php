<?php

declare(strict_types=1);

namespace Coleza\Domain\Documents\Proforma;

final class ProformaInvoice
{
    /**
     * @param array<ProformaItem> $items
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private ?int $id,
        private string $proformaNumber,
        private int $userId,
        private ?int $organizationId,
        private ?int $orderId,
        private ProformaStatus $status,
        private string $currencyCode,
        private int $subtotalMinor,
        private int $taxTotalMinor,
        private int $totalMinor,
        private int $paidAmountMinor,
        private string $issueDate,
        private string $dueDate,
        private ?string $paidAt = null,
        private ?int $convertedInvoiceId = null,
        private ?string $convertedInvoiceNumber = null,
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

    public function getProformaNumber(): string
    {
        return $this->proformaNumber;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getOrganizationId(): ?int
    {
        return $this->organizationId;
    }

    public function getOrderId(): ?int
    {
        return $this->orderId;
    }

    public function getStatus(): ProformaStatus
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

    public function getPaidAmountMinor(): int
    {
        return $this->paidAmountMinor;
    }

    public function getBalanceDueMinor(): int
    {
        return max(0, $this->totalMinor - $this->paidAmountMinor);
    }

    public function isFullyPaid(): bool
    {
        return $this->paidAmountMinor >= $this->totalMinor && $this->totalMinor > 0;
    }

    public function getIssueDate(): string
    {
        return $this->issueDate;
    }

    public function getDueDate(): string
    {
        return $this->dueDate;
    }

    public function getPaidAt(): ?string
    {
        return $this->paidAt;
    }

    public function getConvertedInvoiceId(): ?int
    {
        return $this->convertedInvoiceId;
    }

    public function getConvertedInvoiceNumber(): ?string
    {
        return $this->convertedInvoiceNumber;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    /**
     * @return array<ProformaItem>
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
}
