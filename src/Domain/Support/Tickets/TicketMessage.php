<?php

declare(strict_types=1);

namespace Coleza\Domain\Support\Tickets;

use DateTimeImmutable;

final class TicketMessage
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly int $id,
        private readonly int $ticketId,
        private readonly int $userId,
        private readonly string $message,
        private readonly bool $isStaff = false,
        private readonly bool $isInternalNote = false,
        private readonly array $metadata = [],
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null
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

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function isStaff(): bool
    {
        return $this->isStaff;
    }

    public function isInternalNote(): bool
    {
        return $this->isInternalNote;
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

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'ticket_id' => $this->ticketId,
            'user_id' => $this->userId,
            'message' => $this->message,
            'is_staff' => $this->isStaff,
            'is_internal_note' => $this->isInternalNote,
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

        return new self(
            id: (int) $data['id'],
            ticketId: (int) $data['ticket_id'],
            userId: (int) $data['user_id'],
            message: (string) $data['message'],
            isStaff: (bool) ($data['is_staff'] ?? false),
            isInternalNote: (bool) ($data['is_internal_note'] ?? false),
            metadata: $metadata,
            createdAt: $createdAt,
            updatedAt: $updatedAt
        );
    }
}
