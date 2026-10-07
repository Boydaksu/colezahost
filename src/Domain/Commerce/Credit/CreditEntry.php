<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Credit;

final class CreditEntry
{
    public const TYPE_CREDIT = 'credit'; // Addition / deposit
    public const TYPE_DEBIT = 'debit';   // Deduction / usage

    public const REF_MANUAL_ADJUSTMENT = 'manual_adjustment';
    public const REF_INVOICE = 'invoice';
    public const REF_PAYMENT = 'payment';
    public const REF_REFUND = 'refund';

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private ?int $id,
        private string $entryNumber,
        private int $userId,
        private ?int $organizationId,
        private string $currencyCode,
        private string $type,
        private int $amountMinor,
        private int $balanceAfterMinor,
        private string $reason,
        private ?string $referenceType = null,
        private ?int $referenceId = null,
        private ?int $adminUserId = null,
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

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getOrganizationId(): ?int
    {
        return $this->organizationId;
    }

    public function getCurrencyCode(): string
    {
        return strtoupper($this->currencyCode);
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

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getReferenceType(): ?string
    {
        return $this->referenceType;
    }

    public function getReferenceId(): ?int
    {
        return $this->referenceId;
    }

    public function getAdminUserId(): ?int
    {
        return $this->adminUserId;
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
            'user_id' => $this->userId,
            'organization_id' => $this->organizationId,
            'currency_code' => $this->getCurrencyCode(),
            'type' => $this->type,
            'amount_minor' => $this->amountMinor,
            'balance_after_minor' => $this->balanceAfterMinor,
            'reason' => $this->reason,
            'reference_type' => $this->referenceType,
            'reference_id' => $this->referenceId,
            'admin_user_id' => $this->adminUserId,
            'metadata' => $this->metadata,
            'created_at' => $this->createdAt,
        ];
    }
}
