<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Canonical;

final class CanonicalInvoiceDto implements CanonicalEntityInterface
{
    /**
     * @param list<array{description: string, amount: float, type?: string, rel_id?: int|string}> $lineItems
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private string $sourceId,
        private string $sourceSystem,
        private string $clientSourceId,
        private string $invoiceNumber,
        private float $subtotal,
        private float $tax,
        private float $total,
        private string $currency = 'USD',
        private string $status = 'paid', // 'paid', 'unpaid', 'cancelled', 'refunded'
        private ?string $date = null,
        private ?string $dueDate = null,
        private ?string $datePaid = null,
        private array $lineItems = [],
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
        return 'invoice';
    }

    public function getClientSourceId(): string
    {
        return $this->clientSourceId;
    }

    public function getInvoiceNumber(): string
    {
        return $this->invoiceNumber;
    }

    public function getSubtotal(): float
    {
        return $this->subtotal;
    }

    public function getTax(): float
    {
        return $this->tax;
    }

    public function getTotal(): float
    {
        return $this->total;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getDate(): ?string
    {
        return $this->date;
    }

    public function getDueDate(): ?string
    {
        return $this->dueDate;
    }

    public function getDatePaid(): ?string
    {
        return $this->datePaid;
    }

    /**
     * @return list<array{description: string, amount: float, type?: string, rel_id?: int|string}>
     */
    public function getLineItems(): array
    {
        return $this->lineItems;
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
            'invoice_number' => $this->invoiceNumber,
            'subtotal' => $this->subtotal,
            'tax' => $this->tax,
            'total' => $this->total,
            'currency' => $this->currency,
            'status' => $this->status,
            'date' => $this->date,
            'due_date' => $this->dueDate,
            'date_paid' => $this->datePaid,
            'line_items' => $this->lineItems,
            'metadata' => $this->metadata,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
