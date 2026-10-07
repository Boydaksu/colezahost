<?php

declare(strict_types=1);

namespace Coleza\Domain\Finance\Expenses;

use Coleza\Domain\Commerce\Recurring\BillingPeriod;
use Coleza\Domain\Pricing\Entities\PriceCycle;

final class RecurringExpense
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private ?int $id,
        private string $title,
        private int $categoryId,
        private ?int $vendorId,
        private int $financialAccountId,
        private string $cycle,
        private int $amountMinor,
        private int $taxAmountMinor,
        private string $currencyCode,
        private string $nextDueDate,
        private bool $isActive = true,
        private ?string $notes = null,
        private array $metadata = [],
        private ?string $createdAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getCategoryId(): int
    {
        return $this->categoryId;
    }

    public function getVendorId(): ?int
    {
        return $this->vendorId;
    }

    public function getFinancialAccountId(): int
    {
        return $this->financialAccountId;
    }

    public function getCycle(): string
    {
        return $this->cycle;
    }

    public function getAmountMinor(): int
    {
        return $this->amountMinor;
    }

    public function getTaxAmountMinor(): int
    {
        return $this->taxAmountMinor;
    }

    public function getTotalMinor(): int
    {
        return $this->amountMinor + $this->taxAmountMinor;
    }

    public function getCurrencyCode(): string
    {
        return strtoupper(trim($this->currencyCode));
    }

    public function getNextDueDate(): string
    {
        return $this->nextDueDate;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    /**
     * Advance next due date by cycle.
     */
    public function computeNextDueDate(): string
    {
        return BillingPeriod::calculateNextDueDate($this->nextDueDate, $this->cycle);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'category_id' => $this->categoryId,
            'vendor_id' => $this->vendorId,
            'financial_account_id' => $this->financialAccountId,
            'cycle' => $this->cycle,
            'amount_minor' => $this->amountMinor,
            'tax_amount_minor' => $this->taxAmountMinor,
            'total_minor' => $this->getTotalMinor(),
            'currency_code' => $this->getCurrencyCode(),
            'next_due_date' => $this->nextDueDate,
            'is_active' => $this->isActive,
            'notes' => $this->notes,
            'metadata' => $this->metadata,
            'created_at' => $this->createdAt,
        ];
    }
}
