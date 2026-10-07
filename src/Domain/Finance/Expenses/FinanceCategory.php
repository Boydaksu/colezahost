<?php

declare(strict_types=1);

namespace Coleza\Domain\Finance\Expenses;

final class FinanceCategory
{
    public const TYPE_INCOME = 'income';
    public const TYPE_EXPENSE = 'expense';

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private ?int $id,
        private string $code,
        private string $name,
        private string $type = self::TYPE_EXPENSE,
        private ?string $description = null,
        private bool $isTaxDeductible = true,
        private bool $isActive = true,
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

    public function getType(): string
    {
        return $this->type;
    }

    public function isIncome(): bool
    {
        return $this->type === self::TYPE_INCOME;
    }

    public function isExpense(): bool
    {
        return $this->type === self::TYPE_EXPENSE;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function isTaxDeductible(): bool
    {
        return $this->isTaxDeductible;
    }

    public function isActive(): bool
    {
        return $this->isActive;
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
            'type' => $this->type,
            'description' => $this->description,
            'is_tax_deductible' => $this->isTaxDeductible,
            'is_active' => $this->isActive,
            'metadata' => $this->metadata,
            'created_at' => $this->createdAt,
        ];
    }
}
