<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Canonical;

final class CanonicalPaymentDto implements CanonicalEntityInterface
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private string $sourceId,
        private string $sourceSystem,
        private string $clientSourceId,
        private ?string $invoiceSourceId,
        private float $amount,
        private float $feeAmount = 0.0,
        private string $currency = 'USD',
        private ?string $transactionId = null,
        private string $gateway = 'banktransfer',
        private string $status = 'completed', // 'completed', 'refunded', 'reversed'
        private ?string $paymentDate = null,
        private array $metadata = []
    ) {
    }

    public function getSourceId(): string
    {
        return $this->sourceId;
    }

    public function getSourceSystem(): string
    {
        return $this->sourceSystem;
    }

    public function getEntityType(): string
    {
        return 'payment';
    }

    public function getClientSourceId(): string
    {
        return $this->clientSourceId;
    }

    public function getInvoiceSourceId(): ?string
    {
        return $this->invoiceSourceId;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function getFeeAmount(): float
    {
        return $this->feeAmount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getTransactionId(): ?string
    {
        return $this->transactionId;
    }

    public function getGateway(): string
    {
        return $this->gateway;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getPaymentDate(): ?string
    {
        return $this->paymentDate;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function toArray(): array
    {
        return [
            'source_id' => $this->sourceId,
            'source_system' => $this->sourceSystem,
            'entity_type' => $this->getEntityType(),
            'client_source_id' => $this->clientSourceId,
            'invoice_source_id' => $this->invoiceSourceId,
            'amount' => $this->amount,
            'fee_amount' => $this->feeAmount,
            'currency' => $this->currency,
            'transaction_id' => $this->transactionId,
            'gateway' => $this->gateway,
            'status' => $this->status,
            'payment_date' => $this->paymentDate,
            'metadata' => $this->metadata,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
