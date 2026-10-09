<?php

declare(strict_types=1);

namespace Coleza\Domain\Privacy\Retention;

use DateTimeImmutable;

final class LegalHold
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly ?int $id,
        private readonly string $holdReference,
        private readonly string $title,
        private readonly string $reason,
        private readonly LegalHoldScopeType $scopeType,
        private readonly ?int $scopeId,
        private readonly ?string $scopeIdentifier,
        private readonly string $issuedByAuthority,
        private readonly int $createdByStaffId,
        private readonly bool $isActive = true,
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $releasedAt = null,
        private readonly ?int $releasedByStaffId = null,
        private readonly ?string $releaseNotes = null,
        private readonly array $metadata = []
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHoldReference(): string
    {
        return $this->holdReference;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getScopeType(): LegalHoldScopeType
    {
        return $this->scopeType;
    }

    public function getScopeId(): ?int
    {
        return $this->scopeId;
    }

    public function getScopeIdentifier(): ?string
    {
        return $this->scopeIdentifier;
    }

    public function getIssuedByAuthority(): string
    {
        return $this->issuedByAuthority;
    }

    public function getCreatedByStaffId(): int
    {
        return $this->createdByStaffId;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt ?? new DateTimeImmutable();
    }

    public function getReleasedAt(): ?DateTimeImmutable
    {
        return $this->releasedAt;
    }

    public function getReleasedByStaffId(): ?int
    {
        return $this->releasedByStaffId;
    }

    public function getReleaseNotes(): ?string
    {
        return $this->releaseNotes;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function matchesUser(int $userId): bool
    {
        if (!$this->isActive) {
            return false;
        }

        if ($this->scopeType->isGlobal()) {
            return true;
        }

        return $this->scopeType === LegalHoldScopeType::USER && $this->scopeId === $userId;
    }

    public function matchesOrganization(int $orgId): bool
    {
        if (!$this->isActive) {
            return false;
        }

        if ($this->scopeType->isGlobal()) {
            return true;
        }

        return $this->scopeType === LegalHoldScopeType::ORGANIZATION && $this->scopeId === $orgId;
    }

    public function matchesResource(string $identifier): bool
    {
        if (!$this->isActive) {
            return false;
        }

        if ($this->scopeType->isGlobal()) {
            return true;
        }

        return $this->scopeType === LegalHoldScopeType::RESOURCE
            && strtolower(trim((string) $this->scopeIdentifier)) === strtolower(trim($identifier));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'hold_reference' => $this->holdReference,
            'title' => $this->title,
            'reason' => $this->reason,
            'scope_type' => $this->scopeType->value,
            'scope_id' => $this->scopeId,
            'scope_identifier' => $this->scopeIdentifier,
            'issued_by_authority' => $this->issuedByAuthority,
            'created_by_staff_id' => $this->createdByStaffId,
            'is_active' => $this->isActive,
            'created_at' => $this->getCreatedAt()->format('Y-m-d H:i:s'),
            'released_at' => $this->releasedAt?->format('Y-m-d H:i:s'),
            'released_by_staff_id' => $this->releasedByStaffId,
            'release_notes' => $this->releaseNotes,
            'metadata' => $this->metadata,
        ];
    }
}
