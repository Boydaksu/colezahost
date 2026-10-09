<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Canonical;

final class CanonicalTicketDto implements CanonicalEntityInterface
{
    /**
     * @param list<array{source_id: string, author_type: string, author_id?: string|int, message: string, date: string, attachments?: list<string>}> $replies
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private string $sourceId,
        private string $sourceSystem,
        private string $clientSourceId,
        private string $department,
        private string $subject,
        private string $message,
        private string $priority = 'medium', // 'low', 'medium', 'high', 'urgent'
        private string $status = 'closed',   // 'open', 'in_progress', 'answered', 'closed'
        private ?string $ticketMask = null,
        private ?string $createdAt = null,
        private ?string $lastReplyAt = null,
        private array $replies = [],
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
        return 'ticket';
    }

    public function getClientSourceId(): string
    {
        return $this->clientSourceId;
    }

    public function getDepartment(): string
    {
        return $this->department;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getPriority(): string
    {
        return $this->priority;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getTicketMask(): ?string
    {
        return $this->ticketMask;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getLastReplyAt(): ?string
    {
        return $this->lastReplyAt;
    }

    /**
     * @return list<array{source_id: string, author_type: string, author_id?: string|int, message: string, date: string, attachments?: list<string>}>
     */
    public function getReplies(): array
    {
        return $this->replies;
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
            'department' => $this->department,
            'subject' => $this->subject,
            'message' => $this->message,
            'priority' => $this->priority,
            'status' => $this->status,
            'ticket_mask' => $this->ticketMask,
            'created_at' => $this->createdAt,
            'last_reply_at' => $this->lastReplyAt,
            'replies' => $this->replies,
            'metadata' => $this->metadata,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
