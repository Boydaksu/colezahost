<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Services;

final class Service
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private ?int $id,
        private string $serviceNumber,
        private int $userId,
        private ?int $organizationId,
        private ?int $orderId,
        private ?int $orderItemId,
        private int $productId,
        private string $status,
        private string $billingCycle,
        private int $recurringAmountMinor,
        private string $currencyCode,
        private string $registrationDate,
        private string $nextDueDate,
        private ?string $domain = null,
        private ?string $username = null,
        private ?string $passwordEncrypted = null,
        private ?string $serverName = null,
        private ?string $ipAddress = null,
        private ?string $suspensionReason = null,
        private ?string $terminationDate = null,
        private ?string $notes = null,
        private array $metadata = [],
        private ?string $createdAt = null,
        private ?ServicePlacement $placement = null,
        private ?ServiceBillingRelation $billingRelation = null,
        private ?ServiceCancellationRequest $cancellationRequest = null,
        private int $lockVersion = 1
    ) {
        if ($this->billingRelation === null) {
            $this->billingRelation = new ServiceBillingRelation(
                billingCycle: $this->billingCycle,
                recurringAmountMinor: $this->recurringAmountMinor,
                currencyCode: $this->currencyCode,
                registrationDate: $this->registrationDate,
                nextDueDate: $this->nextDueDate
            );
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getServiceNumber(): string
    {
        return $this->serviceNumber;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getOrganizationId(): ?int
    {
        return $this->organizationId;
    }

    public function getOrderId(): ?int
    {
        return $this->orderId;
    }

    public function getOrderItemId(): ?int
    {
        return $this->orderItemId;
    }

    public function getProductId(): int
    {
        return $this->productId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getBillingCycle(): string
    {
        return $this->billingCycle;
    }

    public function getRecurringAmountMinor(): int
    {
        return $this->recurringAmountMinor;
    }

    public function getCurrencyCode(): string
    {
        return strtoupper($this->currencyCode);
    }

    public function getRegistrationDate(): string
    {
        return $this->registrationDate;
    }

    public function getNextDueDate(): string
    {
        return $this->nextDueDate;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function getPasswordEncrypted(): ?string
    {
        return $this->passwordEncrypted;
    }

    public function getServerName(): ?string
    {
        return $this->serverName;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getSuspensionReason(): ?string
    {
        return $this->suspensionReason;
    }

    public function getTerminationDate(): ?string
    {
        return $this->terminationDate;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getPlacement(): ?ServicePlacement
    {
        return $this->placement;
    }

    public function setPlacement(?ServicePlacement $placement): void
    {
        $this->placement = $placement;
    }

    public function getBillingRelation(): ServiceBillingRelation
    {
        return $this->billingRelation ?? new ServiceBillingRelation(
            billingCycle: $this->billingCycle,
            recurringAmountMinor: $this->recurringAmountMinor,
            currencyCode: $this->currencyCode,
            registrationDate: $this->registrationDate,
            nextDueDate: $this->nextDueDate
        );
    }

    public function getCancellationRequest(): ?ServiceCancellationRequest
    {
        return $this->cancellationRequest;
    }

    public function setCancellationRequest(?ServiceCancellationRequest $request): void
    {
        $this->cancellationRequest = $request;
    }

    public function hasPendingCancellation(): bool
    {
        return $this->cancellationRequest !== null && $this->cancellationRequest->isPending();
    }

    public function isPending(): bool
    {
        return $this->status === ServiceStateMachine::STATUS_PENDING;
    }

    public function isActive(): bool
    {
        return $this->status === ServiceStateMachine::STATUS_ACTIVE;
    }

    public function isSuspended(): bool
    {
        return $this->status === ServiceStateMachine::STATUS_SUSPENDED;
    }

    public function isTerminated(): bool
    {
        return $this->status === ServiceStateMachine::STATUS_TERMINATED;
    }

    public function isCancelled(): bool
    {
        return $this->status === ServiceStateMachine::STATUS_CANCELLED;
    }

    public function getLockVersion(): int
    {
        return $this->lockVersion;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'service_number' => $this->serviceNumber,
            'user_id' => $this->userId,
            'organization_id' => $this->organizationId,
            'order_id' => $this->orderId,
            'order_item_id' => $this->orderItemId,
            'product_id' => $this->productId,
            'status' => $this->status,
            'billing_cycle' => $this->billingCycle,
            'recurring_amount_minor' => $this->recurringAmountMinor,
            'currency_code' => $this->getCurrencyCode(),
            'registration_date' => $this->registrationDate,
            'next_due_date' => $this->nextDueDate,
            'domain' => $this->domain,
            'username' => $this->username,
            'server_name' => $this->serverName,
            'ip_address' => $this->ipAddress,
            'suspension_reason' => $this->suspensionReason,
            'termination_date' => $this->terminationDate,
            'notes' => $this->notes,
            'metadata' => $this->metadata,
            'placement' => $this->placement?->toArray(),
            'billing_relation' => $this->getBillingRelation()->toArray(),
            'cancellation_request' => $this->cancellationRequest?->toArray(),
            'lock_version' => $this->lockVersion,
            'created_at' => $this->createdAt,
        ];
    }
}
