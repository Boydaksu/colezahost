<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Canonical;

final class CanonicalDomainDto implements CanonicalEntityInterface
{
    /**
     * @param list<string> $nameServers
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private string $sourceId,
        private string $sourceSystem,
        private string $clientSourceId,
        private string $domainName,
        private ?string $registrar = null,
        private string $status = 'active', // 'active', 'expired', 'transferred_out', 'pending'
        private float $recurringAmount = 0.0,
        private string $currency = 'USD',
        private int $registrationPeriodYears = 1,
        private ?string $registrationDate = null,
        private ?string $expiryDate = null,
        private ?string $nextDueDate = null,
        private bool $autoRenew = true,
        private bool $idProtection = false,
        private array $nameServers = [],
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
        return 'domain';
    }

    public function getClientSourceId(): string
    {
        return $this->clientSourceId;
    }

    public function getDomainName(): string
    {
        return $this->domainName;
    }

    public function getRegistrar(): ?string
    {
        return $this->registrar;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getRecurringAmount(): float
    {
        return $this->recurringAmount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getRegistrationPeriodYears(): int
    {
        return $this->registrationPeriodYears;
    }

    public function getRegistrationDate(): ?string
    {
        return $this->registrationDate;
    }

    public function getExpiryDate(): ?string
    {
        return $this->expiryDate;
    }

    public function getNextDueDate(): ?string
    {
        return $this->nextDueDate;
    }

    public function isAutoRenew(): bool
    {
        return $this->autoRenew;
    }

    public function hasIdProtection(): bool
    {
        return $this->idProtection;
    }

    /**
     * @return list<string>
     */
    public function getNameServers(): array
    {
        return $this->nameServers;
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
            'domain_name' => $this->domainName,
            'registrar' => $this->registrar,
            'status' => $this->status,
            'recurring_amount' => $this->recurringAmount,
            'currency' => $this->currency,
            'registration_period_years' => $this->registrationPeriodYears,
            'registration_date' => $this->registrationDate,
            'expiry_date' => $this->expiryDate,
            'next_due_date' => $this->nextDueDate,
            'auto_renew' => $this->autoRenew,
            'id_protection' => $this->idProtection,
            'nameservers' => $this->nameServers,
            'metadata' => $this->metadata,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
