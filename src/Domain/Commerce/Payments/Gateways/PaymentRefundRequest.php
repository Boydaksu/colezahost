<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Payments\Gateways;

final class PaymentRefundRequest
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private string $paymentTransactionId,
        private int $refundAmountMinor,
        private string $currencyCode = 'TRY',
        private ?string $reason = null,
        private array $metadata = []
    ) {
    }

    public function getPaymentTransactionId(): string
    {
        return $this->paymentTransactionId;
    }

    public function getRefundAmountMinor(): int
    {
        return $this->refundAmountMinor;
    }

    public function getRefundAmountDecimal(): float
    {
        return $this->refundAmountMinor / 100.0;
    }

    public function getCurrencyCode(): string
    {
        return strtoupper($this->currencyCode);
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
