<?php

declare(strict_types=1);

namespace Coleza\Domain\Domains\Catalog;

use DateTimeImmutable;

final class TldPricing
{
    public const OPERATION_REGISTER = 'register';
    public const OPERATION_RENEW = 'renew';
    public const OPERATION_TRANSFER = 'transfer';
    public const OPERATION_RESTORE = 'restore';

    public function __construct(
        private readonly ?int $id,
        private readonly int $tldId,
        private readonly string $operation,
        private readonly int $years,
        private readonly string $currencyCode,
        private readonly int $priceMinor,
        private readonly ?int $costMinor = null,
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTldId(): int
    {
        return $this->tldId;
    }

    public function getOperation(): string
    {
        return $this->operation;
    }

    public function getYears(): int
    {
        return $this->years;
    }

    public function getCurrencyCode(): string
    {
        return strtoupper($this->currencyCode);
    }

    public function getPriceMinor(): int
    {
        return $this->priceMinor;
    }

    public function getCostMinor(): ?int
    {
        return $this->costMinor;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tld_id' => $this->tldId,
            'operation' => $this->operation,
            'years' => $this->years,
            'currency_code' => $this->getCurrencyCode(),
            'price_minor' => $this->priceMinor,
            'cost_minor' => $this->costMinor,
            'created_at' => $this->createdAt?->format(DateTimeImmutable::ATOM),
            'updated_at' => $this->updatedAt?->format(DateTimeImmutable::ATOM),
        ];
    }
}
