<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Recurring\Scheduler;

use Coleza\Domain\Commerce\Services\Lifecycle\OverdueBatchReport;
use DateTimeImmutable;

final class CatchupBatchReport
{
    /**
     * @param array<int, RenewalBatchReport> $renewalReports
     * @param array<int, OverdueBatchReport> $overdueReports
     */
    public function __construct(
        private readonly string $schedulerName,
        private readonly string $startDate,
        private readonly string $endDate,
        private readonly int $missedDaysCount,
        private readonly array $renewalReports = [],
        private readonly array $overdueReports = [],
        private readonly float $durationMs = 0.0,
        private readonly ?DateTimeImmutable $completedAt = null
    ) {
    }

    public function getSchedulerName(): string
    {
        return $this->schedulerName;
    }

    public function getStartDate(): string
    {
        return $this->startDate;
    }

    public function getEndDate(): string
    {
        return $this->endDate;
    }

    public function getMissedDaysCount(): int
    {
        return $this->missedDaysCount;
    }

    /**
     * @return array<int, RenewalBatchReport>
     */
    public function getRenewalReports(): array
    {
        return $this->renewalReports;
    }

    /**
     * @return array<int, OverdueBatchReport>
     */
    public function getOverdueReports(): array
    {
        return $this->overdueReports;
    }

    public function getTotalInvoicesGenerated(): int
    {
        $sum = 0;
        foreach ($this->renewalReports as $report) {
            $sum += $report->getGeneratedCount();
        }
        return $sum;
    }

    public function getTotalSuspended(): int
    {
        $sum = 0;
        foreach ($this->overdueReports as $report) {
            $sum += $report->getSuspendedCount();
        }
        return $sum;
    }

    public function getTotalTerminated(): int
    {
        $sum = 0;
        foreach ($this->overdueReports as $report) {
            $sum += $report->getTerminatedCount();
        }
        return $sum;
    }

    public function getDurationMs(): float
    {
        return $this->durationMs;
    }

    public function getCompletedAt(): DateTimeImmutable
    {
        return $this->completedAt ?? new DateTimeImmutable();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'scheduler_name' => $this->schedulerName,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'missed_days_count' => $this->missedDaysCount,
            'total_invoices_generated' => $this->getTotalInvoicesGenerated(),
            'total_suspended' => $this->getTotalSuspended(),
            'total_terminated' => $this->getTotalTerminated(),
            'duration_ms' => $this->durationMs,
            'completed_at' => $this->getCompletedAt()->format(DateTimeImmutable::ATOM),
            'renewal_reports' => array_map(fn (RenewalBatchReport $r) => $r->toArray(), $this->renewalReports),
            'overdue_reports' => array_map(fn (OverdueBatchReport $r) => $r->toArray(), $this->overdueReports),
        ];
    }
}
