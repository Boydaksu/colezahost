<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Canonical;

final class CanonicalServiceDto implements CanonicalEntityInterface
{
    /**
     * @param array<string, mixed> $customFields
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private string $sourceId,
        private string $sourceSystem,
        private string $clientSourceId,
        private string $productSourceId,
        private ?string $domain = null,
        private ?string $username = null,
        private ?string $dedicatedIp = null,
        private string $status = 'active', // 'active', 'suspended', 'terminated', 'pending'
        private string $billingCycle = 'monthly',
        private float $recurringAmount = 0.0,
        private string $currency = 'USD',
        private ?string $registrationDate = null,
        private ?string $nextDueDate = null,
        private ?string $suspensionReason = null,
        private array $customFields = [],
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
        return 'service';
    }

    public function getClientSourceId(): string
    {
        return $this->clientSourceId;
    }

    public function getProductSourceId(): string
    {
        return $this->productSourceId;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function getDedicatedIp(): ?string
    {
        return $this->dedicatedIp;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getBillingCycle(): string
    {
        return $this->billingCycle;
    }

    public function getRecurringAmount(): float
    {
        return $this->recurringAmount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getRegistrationDate(): ?string
    {
        return $this->registrationDate;
    }

    public function getNextDueDate(): ?string
    {
        return $this->nextDueDate;
    }

    public function getSuspensionReason(): ?string
    {
        return $this->suspensionReason;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCustomFields(): array
    {
        return $this->customFields;
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
            'product_source_id' => $this->productSourceId,
            'domain' => $this->domain,
            'username' => $this->username,
            'dedicated_ip' => $this->dedicatedIp,
            'status' => $this->status,
            'billing_cycle' => $this->billingCycle,
            'recurring_amount' => $this->recurringAmount,
            'currency' => $this->currency,
            'registration_date' => $this->registrationDate,
            'next_due_date' => $this->nextDueDate,
            'suspension_reason' => $this->suspensionReason,
            'custom_fields' => $this->customFields,
            'metadata' => $this->metadata,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
