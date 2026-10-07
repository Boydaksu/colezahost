<?php

declare(strict_types=1);

namespace Coleza\Domain\Finance\Profitability;

final class GatewaySettlement
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private ?int $id,
        private string $settlementNumber,
        private string $gatewayCode,
        private int $sourceAccountId,
        private int $destinationAccountId,
        private int $grossAmountMinor,
        private int $feeAmountMinor,
        private int $netAmountMinor,
        private string $currencyCode,
        private string $settlementDate,
        private string $status = self::STATUS_COMPLETED,
        private int $transactionCount = 1,
        private ?string $payoutReference = null,
        private ?string $notes = null,
        private array $metadata = [],
        private ?string $createdAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSettlementNumber(): string
    {
        return $this->settlementNumber;
    }

    public function getGatewayCode(): string
    {
        return strtoupper(trim($this->gatewayCode));
    }

    public function getSourceAccountId(): int
    {
        return $this->sourceAccountId;
    }

    public function getDestinationAccountId(): int
    {
        return $this->destinationAccountId;
    }

    public function getGrossAmountMinor(): int
    {
        return $this->grossAmountMinor;
    }

    public function getFeeAmountMinor(): int
    {
        return $this->feeAmountMinor;
    }

    public function getNetAmountMinor(): int
    {
        return $this->netAmountMinor;
    }

    public function getCurrencyCode(): string
    {
        return strtoupper(trim($this->currencyCode));
    }

    public function getSettlementDate(): string
    {
        return $this->settlementDate;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getTransactionCount(): int
    {
        return $this->transactionCount;
    }

    public function getPayoutReference(): ?string
    {
        return $this->payoutReference;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
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
            'settlement_number' => $this->settlementNumber,
            'gateway_code' => $this->getGatewayCode(),
            'source_account_id' => $this->sourceAccountId,
            'destination_account_id' => $this->destinationAccountId,
            'gross_amount_minor' => $this->grossAmountMinor,
            'fee_amount_minor' => $this->feeAmountMinor,
            'net_amount_minor' => $this->netAmountMinor,
            'currency_code' => $this->getCurrencyCode(),
            'settlement_date' => $this->settlementDate,
            'status' => $this->status,
            'transaction_count' => $this->transactionCount,
            'payout_reference' => $this->payoutReference,
            'notes' => $this->notes,
            'metadata' => $this->metadata,
            'created_at' => $this->createdAt,
        ];
    }
}
