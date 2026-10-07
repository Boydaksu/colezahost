<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Payments;

final class Refund
{
    public const METHOD_ORIGINAL_GATEWAY = 'original_gateway';
    public const METHOD_MANUAL = 'manual';
    public const METHOD_CREDIT = 'credit';

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private ?int $id,
        private string $refundNumber,
        private int $paymentId,
        private ?int $invoiceId,
        private int $userId,
        private int $amountMinor,
        private string $currencyCode,
        private string $reason,
        private string $refundMethod = self::METHOD_MANUAL,
        private ?string $transactionReference = null,
        private ?string $refundedAt = null,
        private array $metadata = []
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRefundNumber(): string
    {
        return $this->refundNumber;
    }

    public function getPaymentId(): int
    {
        return $this->paymentId;
    }

    public function getInvoiceId(): ?int
    {
        return $this->invoiceId;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getAmountMinor(): int
    {
        return $this->amountMinor;
    }

    public function getCurrencyCode(): string
    {
        return strtoupper($this->currencyCode);
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getRefundMethod(): string
    {
        return $this->refundMethod;
    }

    public function getTransactionReference(): ?string
    {
        return $this->transactionReference;
    }

    public function getRefundedAt(): ?string
    {
        return $this->refundedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'refund_number' => $this->refundNumber,
            'payment_id' => $this->paymentId,
            'invoice_id' => $this->invoiceId,
            'user_id' => $this->userId,
            'amount_minor' => $this->amountMinor,
            'currency_code' => $this->getCurrencyCode(),
            'reason' => $this->reason,
            'refund_method' => $this->refundMethod,
            'transaction_reference' => $this->transactionReference,
            'refunded_at' => $this->refundedAt,
            'metadata' => $this->metadata,
        ];
    }
}
