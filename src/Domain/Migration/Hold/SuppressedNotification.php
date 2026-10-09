<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Hold;

use JsonSerializable;

/**
 * Represents an outbound notification intercepted and suppressed during migration.
 */
final class SuppressedNotification implements JsonSerializable
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private string $batchId,
        private string $recipientEmail,
        private string $notificationType,
        private string $reason,
        private ?string $entityType = null,
        private int|string|null $entityId = null,
        private array $payload = [],
        private ?string $suppressedAt = null,
        private string $status = 'suppressed',
        private ?string $replayedAt = null,
        private ?int $id = null
    ) {
        $this->suppressedAt ??= date('Y-m-d H:i:s');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getBatchId(): string
    {
        return $this->batchId;
    }

    public function getRecipientEmail(): string
    {
        return $this->recipientEmail;
    }

    public function getNotificationType(): string
    {
        return $this->notificationType;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getEntityType(): ?string
    {
        return $this->entityType;
    }

    public function getEntityId(): int|string|null
    {
        return $this->entityId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getSuppressedAt(): string
    {
        return $this->suppressedAt ?? date('Y-m-d H:i:s');
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isSuppressed(): bool
    {
        return $this->status === 'suppressed';
    }

    public function isReplayed(): bool
    {
        return $this->status === 'replayed';
    }

    public function getReplayedAt(): ?string
    {
        return $this->replayedAt;
    }

    public function markReplayed(): void
    {
        $this->status = 'replayed';
        $this->replayedAt = date('Y-m-d H:i:s');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'batch_id' => $this->batchId,
            'recipient_email' => $this->recipientEmail,
            'notification_type' => $this->notificationType,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId !== null ? (string) $this->entityId : null,
            'reason' => $this->reason,
            'payload' => $this->payload,
            'suppressed_at' => $this->suppressedAt,
            'status' => $this->status,
            'replayed_at' => $this->replayedAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
