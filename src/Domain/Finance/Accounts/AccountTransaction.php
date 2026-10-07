<?php

declare(strict_types=1);

namespace Coleza\Domain\Finance\Accounts;

final class AccountTransaction
{
    public const TYPE_CREDIT = 'credit'; // Inflow / deposit
    public const TYPE_DEBIT = 'debit';   // Outflow / withdrawal / expense

    public const SOURCE_PAYMENT = 'payment';
    public const SOURCE_REFUND = 'refund';
    public const SOURCE_EXPENSE = 'expense';
    public const SOURCE_TRANSFER = 'transfer';
    public const SOURCE_ADJUSTMENT = 'adjustment';

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private ?int $id,
        private string $entryNumber,
        private int $accountId,
        private string $type,
        private int $amountMinor,
        private int $balanceAfterMinor,
        private string $currencyCode,
        private string $source,
        private string $description,
        private ?int $referenceId = null,
        private ?string $transactionDate = null,
        private array $metadata = [],
        private ?string $createdAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEntryNumber(): string
    {
        return $this->entryNumber;
    }

    public function getAccountId(): int
    {
        return $this->accountId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isCredit(): bool
    {
        return $this->type === self::TYPE_CREDIT;
    }

    public function isDebit(): bool
    {
        return $this->type === self::TYPE_DEBIT;
    }

    public function getAmountMinor(): int
    {
        return $this->amountMinor;
    }

    public function getBalanceAfterMinor(): int
    {
        return $this->balanceAfterMinor;
    }

    public function getCurrencyCode(): string
    {
        return strtoupper(trim($this->currencyCode));
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getReferenceId(): ?int
    {
        return $this->referenceId;
    }

    public function getTransactionDate(): ?string
    {
        return $this->transactionDate;
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
            'entry_number' => $this->entryNumber,
            'account_id' => $this->accountId,
            'type' => $this->type,
            'amount_minor' => $this->amountMinor,
            'balance_after_minor' => $this->balanceAfterMinor,
            'currency_code' => $this->getCurrencyCode(),
            'source' => $this->source,
            'description' => $this->description,
            'reference_id' => $this->referenceId,
            'transaction_date' => $this->transactionDate,
            'metadata' => $this->metadata,
            'created_at' => $this->createdAt,
        ];
    }
}
