<?php

declare(strict_types=1);

namespace Coleza\Application\Commerce\Orders;

final class CreateOrderCommand
{
    /**
     * @param array<array<string, mixed>> $items
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $items,
        public readonly string $currencyCode = 'TRY',
        public readonly ?int $organizationId = null,
        public readonly array $metadata = []
    ) {
    }
}
