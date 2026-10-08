<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Safety;

use DateTimeImmutable;

final class EmergencyPauseState
{
    public function __construct(
        private readonly bool $isPaused = false,
        private readonly ?string $pausedBy = null,
        private readonly ?string $reason = null,
        private readonly ?DateTimeImmutable $pausedAt = null,
        private readonly ?string $resumedBy = null,
        private readonly ?DateTimeImmutable $resumedAt = null,
        private readonly string $scope = 'ALL'
    ) {
    }

    public static function active(): self
    {
        return new self(isPaused: false);
    }

    public static function paused(string $pausedBy, string $reason, string $scope = 'ALL'): self
    {
        return new self(
            isPaused: true,
            pausedBy: $pausedBy,
            reason: $reason,
            pausedAt: new DateTimeImmutable(),
            scope: strtoupper(trim($scope))
        );
    }

    public function isPaused(): bool
    {
        return $this->isPaused;
    }

    public function getPausedBy(): ?string
    {
        return $this->pausedBy;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getPausedAt(): ?DateTimeImmutable
    {
        return $this->pausedAt;
    }

    public function getResumedBy(): ?string
    {
        return $this->resumedBy;
    }

    public function getResumedAt(): ?DateTimeImmutable
    {
        return $this->resumedAt;
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'is_paused' => $this->isPaused,
            'paused_by' => $this->pausedBy,
            'reason' => $this->reason,
            'paused_at' => $this->pausedAt?->format(DateTimeImmutable::ATOM),
            'resumed_by' => $this->resumedBy,
            'resumed_at' => $this->resumedAt?->format(DateTimeImmutable::ATOM),
            'scope' => $this->scope,
        ];
    }
}
