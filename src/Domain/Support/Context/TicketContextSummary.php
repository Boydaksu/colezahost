<?php

declare(strict_types=1);

namespace Coleza\Domain\Support\Context;

final class TicketContextSummary
{
    /**
     * @param array<string, mixed>|null $service
     * @param array<string, mixed>|null $domain
     * @param array<string, mixed>|null $invoice
     * @param array<string, mixed>|null $order
     */
    public function __construct(
        public readonly int $ticketId,
        public readonly ?array $service = null,
        public readonly ?array $domain = null,
        public readonly ?array $invoice = null,
        public readonly ?array $order = null
    ) {
    }

    public function hasService(): bool
    {
        return $this->service !== null;
    }

    public function hasDomain(): bool
    {
        return $this->domain !== null;
    }

    public function hasInvoice(): bool
    {
        return $this->invoice !== null;
    }

    public function hasOrder(): bool
    {
        return $this->order !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ticket_id' => $this->ticketId,
            'service' => $this->service,
            'domain' => $this->domain,
            'invoice' => $this->invoice,
            'order' => $this->order,
        ];
    }
}
