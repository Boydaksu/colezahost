<?php

declare(strict_types=1);

namespace Coleza\Domain\Support\Context;

final class InMemoryTicketContextProvider implements TicketContextProviderInterface
{
    /** @var array<int, array<string, mixed>> */
    private array $services = [];

    /** @var array<int, array<string, mixed>> */
    private array $domains = [];

    /** @var array<int, array<string, mixed>> */
    private array $invoices = [];

    /** @var array<int, array<string, mixed>> */
    private array $orders = [];

    /**
     * @param array<string, mixed> $data
     */
    public function setService(int $serviceId, array $data): void
    {
        $this->services[$serviceId] = $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function setDomain(int $domainId, array $data): void
    {
        $this->domains[$domainId] = $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function setInvoice(int $invoiceId, array $data): void
    {
        $this->invoices[$invoiceId] = $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function setOrder(int $orderId, array $data): void
    {
        $this->orders[$orderId] = $data;
    }

    public function getServiceContext(int $serviceId): ?array
    {
        return $this->services[$serviceId] ?? null;
    }

    public function getDomainContext(int $domainId): ?array
    {
        return $this->domains[$domainId] ?? null;
    }

    public function getInvoiceContext(int $invoiceId): ?array
    {
        return $this->invoices[$invoiceId] ?? null;
    }

    public function getOrderContext(int $orderId): ?array
    {
        return $this->orders[$orderId] ?? null;
    }
}
