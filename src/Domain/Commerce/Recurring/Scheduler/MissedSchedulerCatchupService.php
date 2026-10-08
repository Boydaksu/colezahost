<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Recurring\Scheduler;

use Coleza\Domain\Commerce\Services\Lifecycle\OverdueGracePolicy;
use Coleza\Domain\Commerce\Services\Lifecycle\OverdueLifecycleWorkflow;
use Coleza\Foundation\Database\Connection;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

final class MissedSchedulerCatchupService
{
    private string $checkpointsTable = 'scheduler_checkpoints';

    public function __construct(
        private readonly Connection $db,
        private readonly ServiceRenewalScheduler $renewalScheduler,
        private readonly OverdueLifecycleWorkflow $overdueWorkflow,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    public function ensureTables(): void
    {
        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                scheduler_name VARCHAR(64) PRIMARY KEY,
                last_checkpoint_date VARCHAR(20) NOT NULL,
                last_run_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->checkpointsTable
        );

        $this->db->statement($sql);
    }

    public function getLastCheckpoint(string $schedulerName): ?string
    {
        $row = $this->db->selectOne(
            sprintf('SELECT last_checkpoint_date FROM %s WHERE scheduler_name = ?', $this->checkpointsTable),
            [$schedulerName]
        );

        return $row ? (string) $row['last_checkpoint_date'] : null;
    }

    public function recordCheckpoint(string $schedulerName, string $checkpointDate): void
    {
        $driver = $this->db->getDriverName();
        $now = date('Y-m-d H:i:s');

        if ($driver === 'sqlite') {
            $sql = sprintf(
                'INSERT INTO %s (scheduler_name, last_checkpoint_date, last_run_at, updated_at)
                 VALUES (?, ?, ?, ?)
                 ON CONFLICT(scheduler_name) DO UPDATE SET
                    last_checkpoint_date = excluded.last_checkpoint_date,
                    updated_at = excluded.updated_at',
                $this->checkpointsTable
            );
            $this->db->statement($sql, [$schedulerName, $checkpointDate, $now, $now]);
        } else {
            $sql = sprintf(
                'INSERT INTO %s (scheduler_name, last_checkpoint_date, last_run_at, updated_at)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    last_checkpoint_date = VALUES(last_checkpoint_date),
                    updated_at = VALUES(updated_at)',
                $this->checkpointsTable
            );
            $this->db->statement($sql, [$schedulerName, $checkpointDate, $now, $now]);
        }
    }

    /**
     * Detect missed schedule gaps and execute catchup runs sequentially for each missed day.
     */
    public function catchup(
        string $schedulerName,
        string $currentDate,
        RenewalPolicy $renewalPolicy,
        OverdueGracePolicy $gracePolicy,
        int $maxCatchupDays = 30
    ): CatchupBatchReport {
        $start = microtime(true);
        $lastCheckpoint = $this->getLastCheckpoint($schedulerName);

        // If no prior checkpoint recorded, initialize at currentDate and run single iteration
        if ($lastCheckpoint === null) {
            $datesToProcess = [$currentDate];
            $startDate = $currentDate;
        } else {
            $startDate = $lastCheckpoint;
            $datesToProcess = $this->computeMissingDates($lastCheckpoint, $currentDate, $maxCatchupDays);
        }

        $renewalReports = [];
        $overdueReports = [];

        foreach ($datesToProcess as $date) {
            $this->logger?->info("Processing catchup iteration for date {$date}", [
                'scheduler' => $schedulerName,
                'target_date' => $date,
            ]);

            // 1. Process renewals for the simulated day
            $renewalReports[] = $this->renewalScheduler->run($renewalPolicy, $date);

            // 2. Process overdue & grace policies for the simulated day
            $overdueReports[] = $this->overdueWorkflow->evaluateAndProcessOverdue($gracePolicy, $date);

            // 3. Incrementally record checkpoint progress
            $this->recordCheckpoint($schedulerName, $date);
        }

        $durationMs = (microtime(true) - $start) * 1000;
        $missedDaysCount = ($lastCheckpoint === null || $lastCheckpoint === $currentDate)
            ? 0
            : count($datesToProcess);

        $batchReport = new CatchupBatchReport(
            schedulerName: $schedulerName,
            startDate: $startDate,
            endDate: $currentDate,
            missedDaysCount: $missedDaysCount,
            renewalReports: $renewalReports,
            overdueReports: $overdueReports,
            durationMs: $durationMs,
            completedAt: new DateTimeImmutable()
        );

        $this->logger?->info("Scheduler catchup completed", [
            'scheduler' => $schedulerName,
            'start_date' => $startDate,
            'end_date' => $currentDate,
            'days_processed' => count($datesToProcess),
            'invoices_generated' => $batchReport->getTotalInvoicesGenerated(),
            'services_suspended' => $batchReport->getTotalSuspended(),
            'services_terminated' => $batchReport->getTotalTerminated(),
        ]);

        return $batchReport;
    }

    /**
     * Compute array of Y-m-d dates from day after lastCheckpoint through currentDate.
     *
     * @return array<int, string>
     */
    public function computeMissingDates(string $lastCheckpoint, string $currentDate, int $maxDays = 30): array
    {
        $last = new DateTimeImmutable($lastCheckpoint);
        $curr = new DateTimeImmutable($currentDate);

        if ($curr <= $last) {
            return [$currentDate];
        }

        $dates = [];
        $cursor = $last->modify('+1 day');

        while ($cursor <= $curr && count($dates) < $maxDays) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }

        return $dates;
    }
}
