<?php

declare(strict_types=1);

namespace Coleza\Domain\Finance\Accounts;

final class FinancialAccount
{
    public const TYPE_BANK = 'bank';
    public const TYPE_CASH = 'cash';
    public const TYPE_GATEWAY = 'gateway';
    public const TYPE_OTHER = 'other';

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private ?int $id,
        private string $code,
        private string $name,
        private string $accountType,
        private string $currencyCode,
        private ?string $accountNumber = null,
        private ?string $bankName = null,
        private ?string $branchName = null,
        private bool $isActive = true,
        private bool $isDefault = false,
        private array $metadata = [],
        private ?string $createdAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return strtoupper(trim($this->code));
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getAccountType(): string
    {
        return $this->accountType;
    }

    public function getCurrencyCode(): string
    {
        return strtoupper(trim($this->currencyCode));
    }

    public function getAccountNumber(): ?string
    {
        return $this->accountNumber;
    }

    public function getBankName(): ?string
    {
        return $this->bankName;
    }

    public function getBranchName(): ?string
    {
        return $this->branchName;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
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
            'code' => $this->getCode(),
            'name' => $this->name,
            'account_type' => $this->accountType,
            'currency_code' => $this->getCurrencyCode(),
            'account_number' => $this->accountNumber,
            'bank_name' => $this->bankName,
            'branch_name' => $this->branchName,
            'is_active' => $this->isActive,
            'is_default' => $this->isDefault,
            'metadata' => $this->metadata,
            'created_at' => $this->createdAt,
        ];
    }
}
