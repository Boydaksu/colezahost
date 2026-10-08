<?php

declare(strict_types=1);

namespace Coleza\Domain\Domains;

use DateTimeImmutable;

final class Domain
{
    /**
     * @param array<int, string> $nameservers
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly int $id,
        private readonly int $userId,
        private readonly ?int $organizationId,
        private readonly string $domain,
        private readonly string $sld,
        private readonly string $tld,
        private readonly string $status = DomainStateMachine::STATUS_PENDING_REGISTRATION,
        private readonly int $registrationPeriodYears = 1,
        private readonly ?string $registrationDate = null,
        private readonly ?string $expiryDate = null,
        private readonly ?string $nextDueDate = null,
        private readonly bool $autoRenew = true,
        private readonly bool $whoisPrivacy = false,
        private readonly bool $dnsManagement = true,
        private readonly bool $emailForwarding = true,
        private readonly bool $isLocked = true,
        private readonly ?string $eppCode = null,
        private readonly ?string $registrarId = null,
        private readonly array $nameservers = [],
        private readonly ?int $subscriptionId = null,
        private readonly array $metadata = [],
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getOrganizationId(): ?int
    {
        return $this->organizationId;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function getSld(): string
    {
        return $this->sld;
    }

    public function getTld(): string
    {
        return $this->tld;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return $this->status === DomainStateMachine::STATUS_ACTIVE;
    }

    public function isPending(): bool
    {
        return DomainStateMachine::isPending($this->status);
    }

    public function isOperable(): bool
    {
        return DomainStateMachine::isOperable($this->status);
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

    public function isWhoisPrivacy(): bool
    {
        return $this->whoisPrivacy;
    }

    public function isDnsManagement(): bool
    {
        return $this->dnsManagement;
    }

    public function isEmailForwarding(): bool
    {
        return $this->emailForwarding;
    }

    public function isLocked(): bool
    {
        return $this->isLocked;
    }

    public function getEppCode(): ?string
    {
        return $this->eppCode;
    }

    public function getRegistrarId(): ?string
    {
        return $this->registrarId;
    }

    /**
     * @return array<int, string>
     */
    public function getNameservers(): array
    {
        return $this->nameservers;
    }

    public function getSubscriptionId(): ?int
    {
        return $this->subscriptionId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function daysUntilExpiry(?string $referenceDate = null): int
    {
        if ($this->expiryDate === null) {
            return 0;
        }

        $ref = new DateTimeImmutable($referenceDate ?? date('Y-m-d'));
        $exp = new DateTimeImmutable($this->expiryDate);

        return (int) $ref->diff($exp)->format('%r%a');
    }

    public function isExpired(?string $referenceDate = null): bool
    {
        return $this->daysUntilExpiry($referenceDate) < 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'organization_id' => $this->organizationId,
            'domain' => $this->domain,
            'sld' => $this->sld,
            'tld' => $this->tld,
            'status' => $this->status,
            'registration_period_years' => $this->registrationPeriodYears,
            'registration_date' => $this->registrationDate,
            'expiry_date' => $this->expiryDate,
            'next_due_date' => $this->nextDueDate,
            'auto_renew' => $this->autoRenew,
            'whois_privacy' => $this->whoisPrivacy,
            'dns_management' => $this->dnsManagement,
            'email_forwarding' => $this->emailForwarding,
            'is_locked' => $this->isLocked,
            'epp_code' => $this->eppCode !== null ? '••••••••' : null,
            'registrar_id' => $this->registrarId,
            'nameservers' => $this->nameservers,
            'subscription_id' => $this->subscriptionId,
            'metadata' => $this->metadata,
            'created_at' => $this->createdAt?->format(DateTimeImmutable::ATOM),
            'updated_at' => $this->updatedAt?->format(DateTimeImmutable::ATOM),
        ];
    }
}
