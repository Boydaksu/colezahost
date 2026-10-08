<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Services\Lifecycle;

final class OverdueEvaluationResult
{
    public function __construct(
        private readonly int $serviceId,
        private readonly string $serviceNumber,
        private readonly string $action, // 'none', 'reminder', 'suspended', 'terminated', 'unsuspended', 'skipped_exempt', 'pending_approval'
        private readonly int $daysOverdue = 0,
        private readonly string $reason = '',
        private readonly bool $notificationSent = false,
        private readonly ?string $errorMessage = null
    ) {
    }

    public static function suspended(int $serviceId, string $serviceNumber, int $daysOverdue, string $reason, bool $notificationSent): self
    {
        return new self($serviceId, $serviceNumber, 'suspended', $daysOverdue, $reason, $notificationSent);
    }

    public static function terminated(int $serviceId, string $serviceNumber, int $daysOverdue, string $reason, bool $notificationSent): self
    {
        return new self($serviceId, $serviceNumber, 'terminated', $daysOverdue, $reason, $notificationSent);
    }

    public static function unsuspended(int $serviceId, string $serviceNumber, string $reason, bool $notificationSent): self
    {
        return new self($serviceId, $serviceNumber, 'unsuspended', 0, $reason, $notificationSent);
    }

    public static function reminder(int $serviceId, string $serviceNumber, int $daysOverdue, bool $notificationSent): self
    {
        return new self($serviceId, $serviceNumber, 'reminder', $daysOverdue, 'Overdue warning reminder', $notificationSent);
    }

    public static function none(int $serviceId, string $serviceNumber, int $daysOverdue = 0): self
    {
        return new self($serviceId, $serviceNumber, 'none', $daysOverdue, 'No action required');
    }

    public static function exempt(int $serviceId, string $serviceNumber, string $tag): self
    {
        return new self($serviceId, $serviceNumber, 'skipped_exempt', 0, "Exempted by tag: {$tag}");
    }

    public static function pendingApproval(int $serviceId, string $serviceNumber, string $reason): self
    {
        return new self($serviceId, $serviceNumber, 'pending_approval', 0, $reason);
    }

    public static function paused(int $serviceId, string $serviceNumber, string $reason): self
    {
        return new self($serviceId, $serviceNumber, 'paused_emergency', 0, $reason);
    }

    public static function failed(int $serviceId, string $serviceNumber, string $errorMessage): self
    {
        return new self($serviceId, $serviceNumber, 'failed', 0, '', false, $errorMessage);
    }

    public function getServiceId(): int
    {
        return $this->serviceId;
    }

    public function getServiceNumber(): string
    {
        return $this->serviceNumber;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getDaysOverdue(): int
    {
        return $this->daysOverdue;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function isNotificationSent(): bool
    {
        return $this->notificationSent;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function isSuccessful(): bool
    {
        return $this->errorMessage === null;
    }

    public function isPaused(): bool
    {
        return $this->action === 'paused_emergency';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'service_id' => $this->serviceId,
            'service_number' => $this->serviceNumber,
            'action' => $this->action,
            'days_overdue' => $this->daysOverdue,
            'reason' => $this->reason,
            'notification_sent' => $this->notificationSent,
            'error_message' => $this->errorMessage,
        ];
    }
}
