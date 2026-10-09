<?php

declare(strict_types=1);

namespace Coleza\Domain\Abuse;

use DateTimeImmutable;

final class AbuseCase
{
    public function __construct(
        private readonly ?int $id,
        private readonly string $caseNumber,
        private readonly AbuseCategory $category,
        private readonly AbuseSeverity $severity,
        private readonly AbuseCaseStatus $status,
        private readonly ?int $organizationId,
        private readonly ?int $userId,
        private readonly string $reporterEmail,
        private readonly ?string $reporterName,
        private readonly string $resourceType,
        private readonly ?int $resourceId,
        private readonly string $resourceIdentifier,
        private readonly string $subject,
        private readonly string $description,
        private readonly ?string $evidenceText = null,
        private readonly ?string $evidenceUrl = null,
        private readonly ?DateTimeImmutable $deadlineAt = null,
        private readonly ?DateTimeImmutable $resolvedAt = null,
        private readonly ?string $resolutionNotes = null,
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCaseNumber(): string
    {
        return $this->caseNumber;
    }

    public function getCategory(): AbuseCategory
    {
        return $this->category;
    }

    public function getSeverity(): AbuseSeverity
    {
        return $this->severity;
    }

    public function getStatus(): AbuseCaseStatus
    {
        return $this->status;
    }

    public function getOrganizationId(): ?int
    {
        return $this->organizationId;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getReporterEmail(): string
    {
        return $this->reporterEmail;
    }

    public function getReporterName(): ?string
    {
        return $this->reporterName;
    }

    public function getResourceType(): string
    {
        return $this->resourceType;
    }

    public function getResourceId(): ?int
    {
        return $this->resourceId;
    }

    public function getResourceIdentifier(): string
    {
        return $this->resourceIdentifier;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getEvidenceText(): ?string
    {
        return $this->evidenceText;
    }

    public function getEvidenceUrl(): ?string
    {
        return $this->evidenceUrl;
    }

    public function getDeadlineAt(): ?DateTimeImmutable
    {
        return $this->deadlineAt;
    }

    public function getResolvedAt(): ?DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function getResolutionNotes(): ?string
    {
        return $this->resolutionNotes;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt ?? new DateTimeImmutable();
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt ?? new DateTimeImmutable();
    }

    public function isOverdue(?DateTimeImmutable $now = null): bool
    {
        if ($this->deadlineAt === null || !$this->status->isOpen()) {
            return false;
        }

        $current = $now ?? new DateTimeImmutable();
        return $current > $this->deadlineAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'case_number' => $this->caseNumber,
            'category' => $this->category->value,
            'severity' => $this->severity->value,
            'status' => $this->status->value,
            'organization_id' => $this->organizationId,
            'user_id' => $this->userId,
            'reporter_email' => $this->reporterEmail,
            'reporter_name' => $this->reporterName,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
            'resource_identifier' => $this->resourceIdentifier,
            'subject' => $this->subject,
            'description' => $this->description,
            'evidence_text' => $this->evidenceText,
            'evidence_url' => $this->evidenceUrl,
            'deadline_at' => $this->deadlineAt?->format('Y-m-d H:i:s'),
            'resolved_at' => $this->resolvedAt?->format('Y-m-d H:i:s'),
            'resolution_notes' => $this->resolutionNotes,
            'created_at' => $this->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $this->getUpdatedAt()->format('Y-m-d H:i:s'),
        ];
    }
}
