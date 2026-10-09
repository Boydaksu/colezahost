<?php

declare(strict_types=1);

namespace Coleza\Domain\Privacy\Requests;

use DateTimeImmutable;

final class PrivacyRequest
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly ?int $id,
        private readonly int $userId,
        private readonly ?int $organizationId,
        private readonly PrivacyRequestType $requestType,
        private readonly PrivacyRequestStatus $status,
        private readonly string $stepUpToken,
        private readonly string $otpCodeHash,
        private readonly int $otpAttempts,
        private readonly DateTimeImmutable $expiresAt,
        private readonly ?DateTimeImmutable $verifiedAt = null,
        private readonly ?DateTimeImmutable $completedAt = null,
        private readonly ?string $exportChecksum = null,
        private readonly array $metadata = [],
        private readonly ?DateTimeImmutable $createdAt = null
    ) {
    }

    public function getId(): ?int
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

    public function getRequestType(): PrivacyRequestType
    {
        return $this->requestType;
    }

    public function getStatus(): PrivacyRequestStatus
    {
        return $this->status;
    }

    public function getStepUpToken(): string
    {
        return $this->stepUpToken;
    }

    public function getOtpCodeHash(): string
    {
        return $this->otpCodeHash;
    }

    public function getOtpAttempts(): int
    {
        return $this->otpAttempts;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getVerifiedAt(): ?DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function getCompletedAt(): ?DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function getExportChecksum(): ?string
    {
        return $this->exportChecksum;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt ?? new DateTimeImmutable();
    }

    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        $current = $now ?? new DateTimeImmutable();
        return $current > $this->expiresAt;
    }

    public function isPendingVerification(): bool
    {
        return $this->status->isPendingVerification();
    }

    public function isVerified(): bool
    {
        return $this->status->isVerified();
    }

    public function isCompleted(): bool
    {
        return $this->status->isCompleted();
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
            'request_type' => $this->requestType->value,
            'status' => $this->status->value,
            'step_up_token' => $this->stepUpToken,
            'otp_attempts' => $this->otpAttempts,
            'expires_at' => $this->expiresAt->format('Y-m-d H:i:s'),
            'verified_at' => $this->verifiedAt?->format('Y-m-d H:i:s'),
            'completed_at' => $this->completedAt?->format('Y-m-d H:i:s'),
            'export_checksum' => $this->exportChecksum,
            'metadata' => $this->metadata,
            'created_at' => $this->getCreatedAt()->format('Y-m-d H:i:s'),
        ];
    }
}
