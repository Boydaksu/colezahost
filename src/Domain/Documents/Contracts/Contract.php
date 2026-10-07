<?php

declare(strict_types=1);

namespace Coleza\Domain\Documents\Contracts;

final class Contract
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private ?int $id,
        private string $contractNumber,
        private int $userId,
        private ?int $organizationId,
        private ?int $serviceId,
        private ?int $orderId,
        private string $title,
        private ContractStatus $status,
        private int $commitmentPeriodMonths,
        private string $startDate,
        private ?string $endDate,
        private string $termsVersion,
        private string $termsText,
        private float $slaCommitmentUptimePercent,
        private ?string $clientAcceptedAt = null,
        private ?string $clientAcceptedIp = null,
        private ?string $clientAcceptedUserAgent = null,
        private ?string $clientAcceptedSignature = null,
        private ?int $internalAcceptedByUserId = null,
        private ?string $internalAcceptedAt = null,
        private ?string $terminatedAt = null,
        private ?string $terminationReason = null,
        private ?int $earlyTerminationFeeMinor = null,
        private ?string $notes = null,
        private ?string $createdAt = null,
        private ?string $updatedAt = null,
        private array $metadata = []
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContractNumber(): string
    {
        return $this->contractNumber;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getOrganizationId(): ?int
    {
        return $this->organizationId;
    }

    public function getServiceId(): ?int
    {
        return $this->serviceId;
    }

    public function getOrderId(): ?int
    {
        return $this->orderId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getStatus(): ContractStatus
    {
        return $this->status;
    }

    public function getCommitmentPeriodMonths(): int
    {
        return $this->commitmentPeriodMonths;
    }

    public function getStartDate(): string
    {
        return $this->startDate;
    }

    public function getEndDate(): ?string
    {
        return $this->endDate;
    }

    public function getTermsVersion(): string
    {
        return $this->termsVersion;
    }

    public function getTermsText(): string
    {
        return $this->termsText;
    }

    public function getSlaCommitmentUptimePercent(): float
    {
        return $this->slaCommitmentUptimePercent;
    }

    public function getClientAcceptedAt(): ?string
    {
        return $this->clientAcceptedAt;
    }

    public function getClientAcceptedIp(): ?string
    {
        return $this->clientAcceptedIp;
    }

    public function getClientAcceptedUserAgent(): ?string
    {
        return $this->clientAcceptedUserAgent;
    }

    public function getClientAcceptedSignature(): ?string
    {
        return $this->clientAcceptedSignature;
    }

    public function getInternalAcceptedByUserId(): ?int
    {
        return $this->internalAcceptedByUserId;
    }

    public function getInternalAcceptedAt(): ?string
    {
        return $this->internalAcceptedAt;
    }

    public function getTerminatedAt(): ?string
    {
        return $this->terminatedAt;
    }

    public function getTerminationReason(): ?string
    {
        return $this->terminationReason;
    }

    public function getEarlyTerminationFeeMinor(): ?int
    {
        return $this->earlyTerminationFeeMinor;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function isClientAccepted(): bool
    {
        return $this->clientAcceptedAt !== null;
    }

    public function isInternalAccepted(): bool
    {
        return $this->internalAcceptedAt !== null;
    }

    public function isFullyAccepted(): bool
    {
        return $this->isClientAccepted() && $this->isInternalAccepted();
    }
}
