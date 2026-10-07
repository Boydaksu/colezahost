<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Payments;

final class Payment
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';

    public const METHOD_BANK_TRANSFER = 'bank_transfer';
    public const METHOD_MANUAL = 'manual';
    public const METHOD_CREDIT = 'credit';

    /**
     * @param array<PaymentAllocation> $allocations
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private ?int $id,
        private string $paymentNumber,
        private int $userId,
        private ?int $organizationId,
        private ?int $invoiceId,
        private string $paymentMethod,
        private int $amountMinor,
        private int $feeMinor = 0,
        private int $netAmountMinor = 0,
        private string $currencyCode = 'USD',
        private string $status = self::STATUS_PENDING,
        private ?string $transactionReference = null,
        private ?string $proofDocumentUrl = null,
        private ?string $notes = null,
        private ?string $paidAt = null,
        private array $metadata = [],
        private array $allocations = [],
        private int $refundedAmountMinor = 0,
        private ?string $createdAt = null
    ) {
        if ($this->netAmountMinor === 0 && $this->amountMinor > 0) {
            $this->netAmountMinor = max(0, $this->amountMinor - $this->feeMinor);
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPaymentNumber(): string
    {
        return $this->paymentNumber;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getOrganizationId(): ?int
    {
        return $this->organizationId;
    }

    public function getInvoiceId(): ?int
    {
        return $this->invoiceId;
    }

    public function getPaymentMethod(): string
    {
        return $this->paymentMethod;
    }

    public function getAmountMinor(): int
    {
        return $this->amountMinor;
    }

    public function getFeeMinor(): int
    {
        return $this->feeMinor;
    }

    public function getNetAmountMinor(): int
    {
        return $this->netAmountMinor;
    }

    public function getCurrencyCode(): string
    {
        return strtoupper($this->currencyCode);
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getTransactionReference(): ?string
    {
        return $this->transactionReference;
    }

    public function getProofDocumentUrl(): ?string
    {
        return $this->proofDocumentUrl;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getPaidAt(): ?string
    {
        return $this->paidAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * @return array<PaymentAllocation>
     */
    public function getAllocations(): array
    {
        return $this->allocations;
    }

    public function getAllocatedAmountMinor(): int
    {
        $sum = 0;
        foreach ($this->allocations as $alloc) {
            $sum += $alloc->getAmountMinor();
        }
        return $sum;
    }

    public function getUnallocatedAmountMinor(): int
    {
        return max(0, $this->amountMinor - $this->getAllocatedAmountMinor());
    }

    public function getRefundedAmountMinor(): int
    {
        return $this->refundedAmountMinor;
    }

    public function getRefundableAmountMinor(): int
    {
        return max(0, $this->amountMinor - $this->refundedAmountMinor);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isRefunded(): bool
    {
        return $this->status === self::STATUS_REFUNDED;
    }

    public function isPartiallyRefunded(): bool
    {
        return $this->status === self::STATUS_PARTIALLY_REFUNDED;
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
            'payment_number' => $this->paymentNumber,
            'user_id' => $this->userId,
            'organization_id' => $this->organizationId,
            'invoice_id' => $this->invoiceId,
            'payment_method' => $this->paymentMethod,
            'amount_minor' => $this->amountMinor,
            'fee_minor' => $this->feeMinor,
            'net_amount_minor' => $this->netAmountMinor,
            'currency_code' => $this->getCurrencyCode(),
            'status' => $this->status,
            'transaction_reference' => $this->transactionReference,
            'proof_document_url' => $this->proofDocumentUrl,
            'notes' => $this->notes,
            'paid_at' => $this->paidAt,
            'metadata' => $this->metadata,
            'allocated_amount_minor' => $this->getAllocatedAmountMinor(),
            'unallocated_amount_minor' => $this->getUnallocatedAmountMinor(),
            'refunded_amount_minor' => $this->getRefundedAmountMinor(),
            'refundable_amount_minor' => $this->getRefundableAmountMinor(),
            'allocations' => array_map(fn($a) => $a->toArray(), $this->allocations),
            'created_at' => $this->createdAt,
        ];
    }
}
