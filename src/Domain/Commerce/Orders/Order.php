<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Orders;

final class Order
{
    /**
     * @param array<OrderItem> $items
     */
    public function __construct(
        private ?int $id,
        private string $orderNumber,
        private int $userId,
        private ?int $organizationId,
        private string $status,
        private string $currencyCode,
        private int $subtotalMinor,
        private int $taxTotalMinor,
        private int $totalMinor,
        private ?string $notes = null,
        private ?string $ipAddress = null,
        private array $items = [],
        private ?string $createdAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrderNumber(): string
    {
        return $this->orderNumber;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getOrganizationId(): ?int
    {
        return $this->organizationId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCurrencyCode(): string
    {
        return strtoupper($this->currencyCode);
    }

    public function getSubtotalMinor(): int
    {
        return $this->subtotalMinor;
    }

    public function getTaxTotalMinor(): int
    {
        return $this->taxTotalMinor;
    }

    public function getTotalMinor(): int
    {
        return $this->totalMinor;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    /**
     * @return array<OrderItem>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function isPendingPayment(): bool
    {
        return $this->status === OrderStateMachine::STATUS_PENDING_PAYMENT;
    }

    public function isActive(): bool
    {
        return $this->status === OrderStateMachine::STATUS_ACTIVE;
    }

    public function isCompleted(): bool
    {
        return $this->status === OrderStateMachine::STATUS_COMPLETED;
    }

    public function isCancelled(): bool
    {
        return $this->status === OrderStateMachine::STATUS_CANCELLED;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->orderNumber,
            'user_id' => $this->userId,
            'organization_id' => $this->organizationId,
            'status' => $this->status,
            'currency_code' => $this->getCurrencyCode(),
            'subtotal_minor' => $this->subtotalMinor,
            'tax_total_minor' => $this->taxTotalMinor,
            'total_minor' => $this->totalMinor,
            'notes' => $this->notes,
            'ip_address' => $this->ipAddress,
            'items' => array_map(fn($item) => $item->toArray(), $this->items),
            'created_at' => $this->createdAt,
        ];
    }
}
