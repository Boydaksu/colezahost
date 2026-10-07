<?php

declare(strict_types=1);

namespace Coleza\Domain\Notifications\Center;

final class InAppNotification
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private ?int $id,
        private int $userId,
        private string $title,
        private string $message,
        private ?string $actionUrl = null,
        private string $type = 'info', // 'info', 'success', 'warning', 'danger'
        private bool $isRead = false,
        private ?string $readAt = null,
        private ?string $createdAt = null,
        private array $metadata = []
    ) {
        $this->createdAt ??= date('c');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getActionUrl(): ?string
    {
        return $this->actionUrl;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isRead(): bool
    {
        return $this->isRead;
    }

    public function getReadAt(): ?string
    {
        return $this->readAt;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt ?? date('c');
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
