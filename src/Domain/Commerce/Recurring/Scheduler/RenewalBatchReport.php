<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Recurring\Scheduler;

use Coleza\Domain\Commerce\Invoices\Invoice;
use DateTimeImmutable;

final class RenewalBatchReport
{
    /**
     * @param array<int, RenewalExecutionResult> $results
     */
    public function __construct(
        private readonly string $asOfDate,
        private readonly array $results = [],
        private readonly float $durationMs = 0.0,
        private readonly ?DateTimeImmutable $runAt = null
    ) {
    }

    public function getAsOfDate(): string
    {
        return $this->asOfDate;
    }

    /**
     * @return array<int, RenewalExecutionResult>
     */
    public function getResults(): array
    {
        return $this->results;
    }

    public function getDurationMs(): float
    {
        return $this->durationMs;
    }

    public function getRunAt(): DateTimeImmutable
    {
        return $this->runAt ?? new DateTimeImmutable();
    }

    public function totalEvaluated(): int
    {
        return count($this->results);
    }

    /**
     * @return array<int, RenewalExecutionResult>
     */
    public function getGeneratedResults(): array
    {
        return array_values(array_filter($this->results, fn (RenewalExecutionResult $r) => $r->isGenerated()));
    }

    public function getGeneratedCount(): int
    {
        return count($this->getGeneratedResults());
    }

    /**
     * @return array<int, RenewalExecutionResult>
     */
    public function getSkippedResults(): array
    {
        return array_values(array_filter($this->results, fn (RenewalExecutionResult $r) => $r->isSkipped()));
    }

    public function getSkippedCount(): int
    {
        return count($this->getSkippedResults());
    }

    /**
     * @return array<int, RenewalExecutionResult>
     */
    public function getFailedResults(): array
    {
        return array_values(array_filter($this->results, fn (RenewalExecutionResult $r) => $r->isFailed()));
    }

    public function getFailedCount(): int
    {
        return count($this->getFailedResults());
    }

    /**
     * @return array<int, Invoice>
     */
    public function getInvoices(): array
    {
        $invoices = [];
        foreach ($this->getGeneratedResults() as $res) {
            if ($res->getInvoice() !== null) {
                $invoices[] = $res->getInvoice();
            }
        }
        return $invoices;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'as_of_date' => $this->asOfDate,
            'total_evaluated' => $this->totalEvaluated(),
            'generated_count' => $this->getGeneratedCount(),
            'skipped_count' => $this->getSkippedCount(),
            'failed_count' => $this->getFailedCount(),
            'duration_ms' => $this->durationMs,
            'run_at' => $this->getRunAt()->format(DateTimeImmutable::ATOM),
            'results' => array_map(fn (RenewalExecutionResult $r) => $r->toArray(), $this->results),
        ];
    }
}
