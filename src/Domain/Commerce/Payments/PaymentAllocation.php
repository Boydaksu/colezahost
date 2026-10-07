<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Payments;

final class PaymentAllocation
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private ?int $id,
        private int $paymentId,
        private int $invoiceId,
        private int $amountMinor,
        private ?int $invoiceItemId = null,
        private ?string $allocatedAt = null,
        private array $metadata = []
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPaymentId(): int
    {
        return $this->paymentId;
    }

    public function getInvoiceId(): int
    {
        return $this->invoiceId;
    }

    public function getAmountMinor(): int
    {
        return $this->amountMinor;
    }

    public function getInvoiceItemId(): ?int
    {
        return $this->invoiceItemId;
    }

    public function getAllocatedAt(): ?string
    {
        return $this->allocatedAt;
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
            'payment_id' => $this->paymentId,
            'invoice_id' => $this->invoiceId,
            'invoice_item_id' => $this->invoiceItemId,
            'amount_minor' => $this->amountMinor,
            'allocated_at' => $this->allocatedAt,
            'metadata' => $this->metadata,
        ];
    }
}
