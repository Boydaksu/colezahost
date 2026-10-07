<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Invoices;

final class Invoice
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_UNPAID = 'unpaid';
    public const STATUS_PAID = 'paid';
    public const STATUS_PARTIALLY_PAID = 'partially_paid';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';

    /**
     * @param array<InvoiceItem> $items
     * @param array<string, mixed> $currencySnapshot
     * @param array<string, mixed> $taxSnapshot
     */
    public function __construct(
        private ?int $id,
        private string $invoiceNumber,
        private int $userId,
        private ?int $organizationId,
        private ?int $orderId,
        private string $status,
        private string $currencyCode,
        private int $subtotalMinor,
        private int $taxTotalMinor,
        private int $totalMinor,
        private int $paidAmountMinor = 0,
        private string $issueDate = '',
        private string $dueDate = '',
        private ?string $paidAt = null,
        private array $currencySnapshot = [],
        private array $taxSnapshot = [],
        private ?string $notes = null,
        private array $items = [],
        private ?string $createdAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInvoiceNumber(): string
    {
        return $this->invoiceNumber;
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

    public function getStatus(): string
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

    /**
     * @return array<string, mixed>
     */
    public function getCurrencySnapshot(): array
    {
        return $this->currencySnapshot;
    }

    /**
     * @return array<string, mixed>
     */
    public function getTaxSnapshot(): array
    {
        return $this->taxSnapshot;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    /**
     * @return array<InvoiceItem>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isUnpaid(): bool
    {
        return $this->status === self::STATUS_UNPAID;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoiceNumber,
            'user_id' => $this->userId,
            'organization_id' => $this->organizationId,
            'order_id' => $this->orderId,
            'status' => $this->status,
            'currency_code' => $this->getCurrencyCode(),
            'subtotal_minor' => $this->subtotalMinor,
            'tax_total_minor' => $this->taxTotalMinor,
            'total_minor' => $this->totalMinor,
            'paid_amount_minor' => $this->paidAmountMinor,
            'balance_due_minor' => $this->getBalanceDueMinor(),
            'issue_date' => $this->issueDate,
            'due_date' => $this->dueDate,
            'paid_at' => $this->paidAt,
            'currency_snapshot' => $this->currencySnapshot,
            'tax_snapshot' => $this->taxSnapshot,
            'notes' => $this->notes,
            'items' => array_map(fn($item) => $item->toArray(), $this->items),
            'created_at' => $this->createdAt,
        ];
    }
}
