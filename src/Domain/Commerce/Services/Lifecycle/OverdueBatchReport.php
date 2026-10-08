<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Services\Lifecycle;

use DateTimeImmutable;

final class OverdueBatchReport
{
    /**
     * @param array<int, OverdueEvaluationResult> $results
     */
    public function __construct(
        private readonly string $referenceDate,
        private readonly array $results = [],
        private readonly float $durationMs = 0.0,
        private readonly ?DateTimeImmutable $evaluatedAt = null
    ) {
    }

    public function getReferenceDate(): string
    {
        return $this->referenceDate;
    }

    /**
     * @return array<int, OverdueEvaluationResult>
     */
    public function getResults(): array
    {
        return $this->results;
    }

    public function totalEvaluated(): int
    {
        return count($this->results);
    }

    public function getSuspendedCount(): int
    {
        return count(array_filter($this->results, fn (OverdueEvaluationResult $r) => $r->getAction() === 'suspended'));
    }

    public function getTerminatedCount(): int
    {
        return count(array_filter($this->results, fn (OverdueEvaluationResult $r) => $r->getAction() === 'terminated'));
    }

    public function getRemindersCount(): int
    {
        return count(array_filter($this->results, fn (OverdueEvaluationResult $r) => $r->getAction() === 'reminder'));
    }

    public function getUnsuspendedCount(): int
    {
        return count(array_filter($this->results, fn (OverdueEvaluationResult $r) => $r->getAction() === 'unsuspended'));
    }

    public function getPausedCount(): int
    {
        return count(array_filter($this->results, fn (OverdueEvaluationResult $r) => $r->isPaused()));
    }

    public function getFailedCount(): int
    {
        return count(array_filter($this->results, fn (OverdueEvaluationResult $r) => !$r->isSuccessful()));
    }

    public function getDurationMs(): float
    {
        return $this->durationMs;
    }

    public function getEvaluatedAt(): DateTimeImmutable
    {
        return $this->evaluatedAt ?? new DateTimeImmutable();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'reference_date' => $this->referenceDate,
            'total_evaluated' => $this->totalEvaluated(),
            'suspended_count' => $this->getSuspendedCount(),
            'terminated_count' => $this->getTerminatedCount(),
            'reminders_count' => $this->getRemindersCount(),
            'unsuspended_count' => $this->getUnsuspendedCount(),
            'paused_count' => $this->getPausedCount(),
            'failed_count' => $this->getFailedCount(),
            'duration_ms' => $this->durationMs,
            'evaluated_at' => $this->getEvaluatedAt()->format(DateTimeImmutable::ATOM),
            'results' => array_map(fn (OverdueEvaluationResult $r) => $r->toArray(), $this->results),
        ];
    }
}
