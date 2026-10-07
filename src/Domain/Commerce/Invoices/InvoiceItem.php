<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Invoices;

final class InvoiceItem
{
    /**
     * @param array<string, mixed> $taxSnapshot
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private ?int $id,
        private int $invoiceId,
        private string $description,
        private int $quantity,
        private int $unitAmountMinor,
        private int $subtotalMinor,
        private int $taxAmountMinor,
        private int $totalMinor,
        private ?int $orderItemId = null,
        private ?int $serviceId = null,
        private array $taxSnapshot = [],
        private array $metadata = []
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInvoiceId(): int
    {
        return $this->invoiceId;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getUnitAmountMinor(): int
    {
        return $this->unitAmountMinor;
    }

    public function getSubtotalMinor(): int
    {
        return $this->subtotalMinor;
    }

    public function getTaxAmountMinor(): int
    {
        return $this->taxAmountMinor;
    }

    public function getTotalMinor(): int
    {
        return $this->totalMinor;
    }

    public function getOrderItemId(): ?int
    {
        return $this->orderItemId;
    }

    public function getServiceId(): ?int
    {
        return $this->serviceId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getTaxSnapshot(): array
    {
        return $this->taxSnapshot;
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
            'invoice_id' => $this->invoiceId,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_amount_minor' => $this->unitAmountMinor,
            'subtotal_minor' => $this->subtotalMinor,
            'tax_amount_minor' => $this->taxAmountMinor,
            'total_minor' => $this->totalMinor,
            'order_item_id' => $this->orderItemId,
            'service_id' => $this->serviceId,
            'tax_snapshot' => $this->taxSnapshot,
            'metadata' => $this->metadata,
        ];
    }
}
