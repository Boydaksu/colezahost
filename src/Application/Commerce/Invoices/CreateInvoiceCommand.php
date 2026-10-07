<?php

declare(strict_types=1);

namespace Coleza\Application\Commerce\Invoices;

final class CreateInvoiceCommand
{
    /**
     * @param array<array<string, mixed>> $items
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $items,
        public readonly string $currencyCode = 'TRY',
        public readonly ?int $orderId = null,
        public readonly ?int $organizationId = null,
        public readonly ?string $dueDate = null,
        public readonly ?string $notes = null,
        public readonly array $metadata = []
    ) {
    }
}
