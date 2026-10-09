<?php

declare(strict_types=1);

namespace Coleza\Domain\Support\Sla;

use DateTimeImmutable;

final class TicketSla
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_MET = 'met';
    public const STATUS_BREACHED = 'breached';
    public const STATUS_PAUSED = 'paused';

    public function __construct(
        private readonly int $id,
        private readonly int $ticketId,
        private readonly ?int $policyId,
        private readonly DateTimeImmutable $firstResponseDueAt,
        private readonly DateTimeImmutable $resolutionDueAt,
        private readonly ?DateTimeImmutable $firstRespondedAt = null,
        private readonly bool $isFirstResponseBreached = false,
        private readonly ?DateTimeImmutable $resolvedAt = null,
        private readonly bool $isResolutionBreached = false,
        private readonly string $status = self::STATUS_ACTIVE,
        private readonly ?DateTimeImmutable $pausedAt = null,
        private readonly int $totalPausedSeconds = 0,
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

    public function getPolicyId(): ?int
    {
        return $this->policyId;
    }

    public function getFirstResponseDueAt(): DateTimeImmutable
    {
        return $this->firstResponseDueAt;
    }

    public function getResolutionDueAt(): DateTimeImmutable
    {
        return $this->resolutionDueAt;
    }

    public function getFirstRespondedAt(): ?DateTimeImmutable
    {
        return $this->firstRespondedAt;
    }

    public function isFirstResponseBreached(): bool
    {
        return $this->isFirstResponseBreached;
    }

    public function getResolvedAt(): ?DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function isResolutionBreached(): bool
    {
        return $this->isResolutionBreached;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getPausedAt(): ?DateTimeImmutable
    {
        return $this->pausedAt;
    }

    public function getTotalPausedSeconds(): int
    {
        return $this->totalPausedSeconds;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isFirstResponseMet(): bool
    {
        return $this->firstRespondedAt !== null && !$this->isFirstResponseBreached;
    }

    public function isResolutionMet(): bool
    {
        return $this->resolvedAt !== null && !$this->isResolutionBreached;
    }

    public function isAnyBreached(): bool
    {
        return $this->isFirstResponseBreached || $this->isResolutionBreached;
    }

    public function isPaused(): bool
    {
        return $this->status === self::STATUS_PAUSED || $this->pausedAt !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'ticket_id' => $this->ticketId,
            'policy_id' => $this->policyId,
            'first_response_due_at' => $this->firstResponseDueAt->format(DateTimeImmutable::ATOM),
            'resolution_due_at' => $this->resolutionDueAt->format(DateTimeImmutable::ATOM),
            'first_responded_at' => $this->firstRespondedAt?->format(DateTimeImmutable::ATOM),
            'is_first_response_breached' => $this->isFirstResponseBreached,
            'resolved_at' => $this->resolvedAt?->format(DateTimeImmutable::ATOM),
            'is_resolution_breached' => $this->isResolutionBreached,
            'status' => $this->status,
            'paused_at' => $this->pausedAt?->format(DateTimeImmutable::ATOM),
            'total_paused_seconds' => $this->totalPausedSeconds,
            'created_at' => $this->createdAt?->format(DateTimeImmutable::ATOM),
            'updated_at' => $this->updatedAt?->format(DateTimeImmutable::ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            ticketId: (int) $data['ticket_id'],
            policyId: isset($data['policy_id']) && $data['policy_id'] !== null ? (int) $data['policy_id'] : null,
            firstResponseDueAt: new DateTimeImmutable((string) $data['first_response_due_at']),
            resolutionDueAt: new DateTimeImmutable((string) $data['resolution_due_at']),
            firstRespondedAt: !empty($data['first_responded_at']) ? new DateTimeImmutable((string) $data['first_responded_at']) : null,
            isFirstResponseBreached: (bool) ($data['is_first_response_breached'] ?? false),
            resolvedAt: !empty($data['resolved_at']) ? new DateTimeImmutable((string) $data['resolved_at']) : null,
            isResolutionBreached: (bool) ($data['is_resolution_breached'] ?? false),
            status: (string) ($data['status'] ?? self::STATUS_ACTIVE),
            pausedAt: !empty($data['paused_at']) ? new DateTimeImmutable((string) $data['paused_at']) : null,
            totalPausedSeconds: (int) ($data['total_paused_seconds'] ?? 0),
            createdAt: !empty($data['created_at']) ? new DateTimeImmutable((string) $data['created_at']) : null,
            updatedAt: !empty($data['updated_at']) ? new DateTimeImmutable((string) $data['updated_at']) : null
        );
    }
}
