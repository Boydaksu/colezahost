<?php

declare(strict_types=1);

namespace Coleza\Domain\Notifications\History;

use Coleza\Domain\Notifications\Channel\NotificationChannel;

final class NotificationLog
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private ?int $id,
        private ?int $userId,
        private NotificationChannel $channel,
        private ?string $templateKey,
        private string $subject,
        private string $recipient,
        private string $status, // 'sent', 'failed', 'delivered'
        private ?string $messageId = null,
        private ?string $error = null,
        private ?string $sentAt = null,
        private array $metadata = []
    ) {
        $this->sentAt ??= date('c');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getChannel(): NotificationChannel
    {
        return $this->channel;
    }

    public function getTemplateKey(): ?string
    {
        return $this->templateKey;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getRecipient(): string
    {
        return $this->recipient;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getSentAt(): string
    {
        return $this->sentAt ?? date('c');
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
