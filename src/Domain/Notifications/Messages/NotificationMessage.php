<?php

declare(strict_types=1);

namespace Coleza\Domain\Notifications\Messages;

use Coleza\Domain\Notifications\Channel\NotificationChannel;
use Coleza\Domain\Notifications\Channel\NotificationPriority;

final class NotificationMessage
{
    /**
     * @param array<string, mixed> $metadata
     * @param array<string> $tags
     */
    public function __construct(
        private string $recipientEmail,
        private string $subject,
        private string $htmlBody,
        private ?string $plainTextBody = null,
        private ?string $recipientName = null,
        private ?int $recipientUserId = null,
        private string $locale = 'en',
        private NotificationChannel $channel = NotificationChannel::EMAIL,
        private NotificationPriority $priority = NotificationPriority::NORMAL,
        private ?string $fromEmail = null,
        private ?string $fromName = null,
        private array $metadata = [],
        private array $tags = []
    ) {
        if ($this->plainTextBody === null) {
            $this->plainTextBody = trim(strip_tags($this->htmlBody));
        }
    }

    public function getRecipientEmail(): string
    {
        return $this->recipientEmail;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getHtmlBody(): string
    {
        return $this->htmlBody;
    }

    public function getPlainTextBody(): string
    {
        return $this->plainTextBody ?? '';
    }

    public function getRecipientName(): ?string
    {
        return $this->recipientName;
    }

    public function getRecipientUserId(): ?int
    {
        return $this->recipientUserId;
    }

    public function getLocale(): string
    {
        return strtolower($this->locale);
    }

    public function getChannel(): NotificationChannel
    {
        return $this->channel;
    }

    public function getPriority(): NotificationPriority
    {
        return $this->priority;
    }

    public function getFromEmail(): ?string
    {
        return $this->fromEmail;
    }

    public function getFromName(): ?string
    {
        return $this->fromName;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * @return array<string>
     */
    public function getTags(): array
    {
        return $this->tags;
    }
}
