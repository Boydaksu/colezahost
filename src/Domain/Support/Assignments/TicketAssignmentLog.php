<?php

declare(strict_types=1);

namespace Coleza\Domain\Support\Assignments;

use DateTimeImmutable;

final class TicketAssignmentLog
{
    public function __construct(
        private readonly int $id,
        private readonly int $ticketId,
        private readonly ?int $previousAssignedTo,
        private readonly ?int $newAssignedTo,
        private readonly ?int $assignedByUserId,
        private readonly string $strategy,
        private readonly ?string $reason = null,
        private readonly ?DateTimeImmutable $createdAt = null
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getTicketId(): int
    {
        return $this->ticketId;
    }

    public function getPreviousAssignedTo(): ?int
    {
        return $this->previousAssignedTo;
    }

    public function getNewAssignedTo(): ?int
    {
        return $this->newAssignedTo;
    }

    public function getAssignedByUserId(): ?int
    {
        return $this->assignedByUserId;
    }

    public function getStrategy(): string
    {
        return $this->strategy;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getCreatedAt(): ?DateTimeImmutable
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
            'ticket_id' => $this->ticketId,
            'previous_assigned_to' => $this->previousAssignedTo,
            'new_assigned_to' => $this->newAssignedTo,
            'assigned_by_user_id' => $this->assignedByUserId,
            'strategy' => $this->strategy,
            'reason' => $this->reason,
            'created_at' => $this->createdAt?->format(DateTimeImmutable::ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $createdAt = !empty($data['created_at']) && is_string($data['created_at'])
            ? new DateTimeImmutable($data['created_at'])
            : null;

        return new self(
            id: (int) $data['id'],
            ticketId: (int) $data['ticket_id'],
            previousAssignedTo: isset($data['previous_assigned_to']) && $data['previous_assigned_to'] !== null
                ? (int) $data['previous_assigned_to']
                : null,
            newAssignedTo: isset($data['new_assigned_to']) && $data['new_assigned_to'] !== null
                ? (int) $data['new_assigned_to']
                : null,
            assignedByUserId: isset($data['assigned_by_user_id']) && $data['assigned_by_user_id'] !== null
                ? (int) $data['assigned_by_user_id']
                : null,
            strategy: (string) $data['strategy'],
            reason: isset($data['reason']) ? (string) $data['reason'] : null,
            createdAt: $createdAt
        );
    }
}
