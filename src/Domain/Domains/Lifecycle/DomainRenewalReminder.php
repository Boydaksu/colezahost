<?php

declare(strict_types=1);

namespace Coleza\Domain\Domains\Lifecycle;

use DateTimeImmutable;

final class DomainRenewalReminder
{
    public const TYPE_BEFORE_30D = 'before_30d';
    public const TYPE_BEFORE_7D = 'before_7d';
    public const TYPE_BEFORE_1D = 'before_1d';
    public const TYPE_AFTER_1D = 'after_1d';
    public const TYPE_AFTER_5D = 'after_5d';
    public const TYPE_REDEMPTION_WARNING = 'redemption_warning';

    public function __construct(
        private readonly ?int $id,
        private readonly int $domainId,
        private readonly string $reminderType,
        private readonly string $expiryDate,
        private readonly string $recipientEmail,
        private readonly ?DateTimeImmutable $sentAt = null,
        private readonly ?DateTimeImmutable $createdAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getReminderType(): string
    {
        return $this->reminderType;
    }

    public function getExpiryDate(): string
    {
        return $this->expiryDate;
    }

    public function getRecipientEmail(): string
    {
        return $this->recipientEmail;
    }

    public function getSentAt(): ?DateTimeImmutable
    {
        return $this->sentAt;
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
            'domain_id' => $this->domainId,
            'reminder_type' => $this->reminderType,
            'expiry_date' => $this->expiryDate,
            'recipient_email' => $this->recipientEmail,
            'sent_at' => $this->sentAt?->format(DateTimeImmutable::ATOM),
            'created_at' => $this->createdAt?->format(DateTimeImmutable::ATOM),
        ];
    }
}
