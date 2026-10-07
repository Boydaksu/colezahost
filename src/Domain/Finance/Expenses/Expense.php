<?php

declare(strict_types=1);

namespace Coleza\Domain\Finance\Expenses;

final class Expense
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private ?int $id,
        private string $expenseNumber,
        private int $categoryId,
        private ?int $vendorId,
        private int $financialAccountId,
        private int $amountMinor,
        private int $taxAmountMinor,
        private int $totalMinor,
        private string $currencyCode,
        private string $paymentDate,
        private ?string $receiptReference = null,
        private ?string $receiptFileUrl = null,
        private ?string $notes = null,
        private bool $isRecurring = false,
        private ?int $recurringExpenseId = null,
        private array $metadata = [],
        private ?string $createdAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getExpenseNumber(): string
    {
        return $this->expenseNumber;
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
        return $this->totalMinor;
    }

    public function getCurrencyCode(): string
    {
        return strtoupper(trim($this->currencyCode));
    }

    public function getPaymentDate(): string
    {
        return $this->paymentDate;
    }

    public function getReceiptReference(): ?string
    {
        return $this->receiptReference;
    }

    public function getReceiptFileUrl(): ?string
    {
        return $this->receiptFileUrl;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function isRecurring(): bool
    {
        return $this->isRecurring;
    }

    public function getRecurringExpenseId(): ?int
    {
        return $this->recurringExpenseId;
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
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'expense_number' => $this->expenseNumber,
            'category_id' => $this->categoryId,
            'vendor_id' => $this->vendorId,
            'financial_account_id' => $this->financialAccountId,
            'amount_minor' => $this->amountMinor,
            'tax_amount_minor' => $this->taxAmountMinor,
            'total_minor' => $this->totalMinor,
            'currency_code' => $this->getCurrencyCode(),
            'payment_date' => $this->paymentDate,
            'receipt_reference' => $this->receiptReference,
            'receipt_file_url' => $this->receiptFileUrl,
            'notes' => $this->notes,
            'is_recurring' => $this->isRecurring,
            'recurring_expense_id' => $this->recurringExpenseId,
            'metadata' => $this->metadata,
            'created_at' => $this->createdAt,
        ];
    }
}
