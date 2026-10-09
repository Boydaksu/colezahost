<?php

declare(strict_types=1);

namespace Coleza\Domain\Support\Context;

interface TicketContextProviderInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function getServiceContext(int $serviceId): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function getDomainContext(int $domainId): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function getInvoiceContext(int $invoiceId): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function getOrderContext(int $orderId): ?array;
}
