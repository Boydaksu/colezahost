<?php

declare(strict_types=1);

namespace Coleza\Domain\Support\Tickets;

use DateTimeImmutable;

final class Ticket
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly int $id,
        private readonly string $ticketNumber,
        private readonly int $userId,
        private readonly int $departmentId,
        private readonly string $subject,
        private readonly string $status = TicketStatus::OPEN,
        private readonly string $priority = TicketPriority::MEDIUM,
        private readonly ?int $organizationId = null,
        private readonly ?int $assignedTo = null,
        private readonly ?int $serviceId = null,
        private readonly ?int $domainId = null,
        private readonly ?DateTimeImmutable $lastReplyAt = null,
        private readonly ?int $lastReplyUserId = null,
        private readonly bool $lastReplyByStaff = false,
        private readonly ?DateTimeImmutable $closedAt = null,
        private readonly array $metadata = [],
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getTicketNumber(): string
    {
        return $this->ticketNumber;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getDepartmentId(): int
    {
        return $this->departmentId;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getPriority(): string
    {
        return $this->priority;
    }

    public function getOrganizationId(): ?int
    {
        return $this->organizationId;
    }

    public function getAssignedTo(): ?int
    {
        return $this->assignedTo;
    }

    public function getServiceId(): ?int
    {
        return $this->serviceId;
    }

    public function getDomainId(): ?int
    {
        return $this->domainId;
    }

    public function getLastReplyAt(): ?DateTimeImmutable
    {
        return $this->lastReplyAt;
    }

    public function getLastReplyUserId(): ?int
    {
        return $this->lastReplyUserId;
    }

    public function isLastReplyByStaff(): bool
    {
        return $this->lastReplyByStaff;
    }

    public function getClosedAt(): ?DateTimeImmutable
    {
        return $this->closedAt;
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

    public function isOpen(): bool
    {
        return TicketStatus::isOpen($this->status);
    }

    public function isAssigned(): bool
    {
        return $this->assignedTo !== null && $this->assignedTo > 0;
    }

    public function isClosed(): bool
    {
        return TicketStatus::isClosed($this->status);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'ticket_number' => $this->ticketNumber,
            'user_id' => $this->userId,
            'department_id' => $this->departmentId,
            'subject' => $this->subject,
            'status' => $this->status,
            'priority' => $this->priority,
            'organization_id' => $this->organizationId,
            'assigned_to' => $this->assignedTo,
            'service_id' => $this->serviceId,
            'domain_id' => $this->domainId,
            'last_reply_at' => $this->lastReplyAt?->format(DateTimeImmutable::ATOM),
            'last_reply_user_id' => $this->lastReplyUserId,
            'last_reply_by_staff' => $this->lastReplyByStaff,
            'closed_at' => $this->closedAt?->format(DateTimeImmutable::ATOM),
            'metadata' => $this->metadata,
            'created_at' => $this->createdAt?->format(DateTimeImmutable::ATOM),
            'updated_at' => $this->updatedAt?->format(DateTimeImmutable::ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $metadata = [];
        if (isset($data['metadata_json']) && is_string($data['metadata_json'])) {
            $decoded = json_decode($data['metadata_json'], true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        } elseif (isset($data['metadata']) && is_array($data['metadata'])) {
            $metadata = $data['metadata'];
        }

        $createdAt = !empty($data['created_at']) && is_string($data['created_at'])
            ? new DateTimeImmutable($data['created_at'])
            : null;

        $updatedAt = !empty($data['updated_at']) && is_string($data['updated_at'])
            ? new DateTimeImmutable($data['updated_at'])
            : null;

        $lastReplyAt = !empty($data['last_reply_at']) && is_string($data['last_reply_at'])
            ? new DateTimeImmutable($data['last_reply_at'])
            : null;

        $closedAt = !empty($data['closed_at']) && is_string($data['closed_at'])
            ? new DateTimeImmutable($data['closed_at'])
            : null;

        return new self(
            id: (int) $data['id'],
            ticketNumber: (string) $data['ticket_number'],
            userId: (int) $data['user_id'],
            departmentId: (int) $data['department_id'],
            subject: (string) $data['subject'],
            status: (string) ($data['status'] ?? TicketStatus::OPEN),
            priority: (string) ($data['priority'] ?? TicketPriority::MEDIUM),
            organizationId: isset($data['organization_id']) && $data['organization_id'] !== null ? (int) $data['organization_id'] : null,
            assignedTo: isset($data['assigned_to']) && $data['assigned_to'] !== null ? (int) $data['assigned_to'] : null,
            serviceId: isset($data['service_id']) && $data['service_id'] !== null ? (int) $data['service_id'] : null,
            domainId: isset($data['domain_id']) && $data['domain_id'] !== null ? (int) $data['domain_id'] : null,
            lastReplyAt: $lastReplyAt,
            lastReplyUserId: isset($data['last_reply_user_id']) && $data['last_reply_user_id'] !== null ? (int) $data['last_reply_user_id'] : null,
            lastReplyByStaff: (bool) ($data['last_reply_by_staff'] ?? false),
            closedAt: $closedAt,
            metadata: $metadata,
            createdAt: $createdAt,
            updatedAt: $updatedAt
        );
    }
}
