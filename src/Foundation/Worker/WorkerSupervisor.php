<?php

declare(strict_types=1);

namespace Coleza\Foundation\Worker;

use Coleza\Foundation\Queue\QueueInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final class WorkerSupervisor
{
    private bool $shouldStop = false;

    public function __construct(
        private QueueInterface $queue,
        private ?LoggerInterface $logger = null,
        private int $memoryBudgetMb = 128,
        private int $timeBudgetSeconds = 60
    ) {
    }

    /**
     * Stop the worker loop gracefully after current job finishes.
     */
    public function stop(): void
    {
        $this->shouldStop = true;
        $this->logger?->info('WorkerSupervisor received stop signal.');
    }

    public function isStopped(): bool
    {
        return $this->shouldStop;
    }

    /**
     * Determine if resource limits have been exceeded.
     */
    public function limitsExceeded(int $startTime): bool
    {
        // 1. Explicit stop flag
        if ($this->shouldStop) {
            return true;
        }

        // 2. Memory budget check
        $usageMb = (int) (memory_get_usage(true) / 1024 / 1024);
        if ($usageMb >= $this->memoryBudgetMb) {
            $this->logger?->warning(sprintf(
                'Worker stopped gracefully: Memory budget exceeded (%d MB >= %d MB limit).',
                $usageMb,
                $this->memoryBudgetMb
            ));
            return true;
        }

        // 3. Time budget check
        $elapsed = time() - $startTime;
        if ($elapsed >= $this->timeBudgetSeconds) {
            $this->logger?->info(sprintf(
                'Worker stopped gracefully: Time budget reached (%d s >= %d s limit).',
                $elapsed,
                $this->timeBudgetSeconds
            ));
            return true;
        }

        return false;
    }

    /**
     * Work jobs continuously across priority queues until stopped, memory exceeded, time budget expired, or max jobs reached.
     *
     * @param array<int, string> $queues
     */
    public function run(array $queues = ['critical', 'default'], int $maxJobs = 0): int
    {
        $startTime = time();
        $processed = 0;

        while (!$this->limitsExceeded($startTime)) {
            if ($maxJobs > 0 && $processed >= $maxJobs) {
                break;
            }

            $jobRan = false;
            foreach ($queues as $q) {
                $job = $this->queue->pop($q);
                if ($job === null) {
                    continue;
                }

                $jobRan = true;
                $jobId = $job->getId();
                try {
                    $job->getJob()->handle();
                    $this->queue->delete($job);
                    $processed++;
                    $this->logger?->info(sprintf('Worker job [%s] on queue [%s] finished.', $jobId, $q));
                } catch (Throwable $e) {
                    $processed++;
                    $this->queue->releaseOrFail($job, $e);
                    $this->logger?->error(sprintf('Worker job [%s] on queue [%s] failed: %s', $jobId, $q, $e->getMessage()));
                }

                // Break inner foreach to re-evaluate higher priority queues next
                break;
            }

            if (!$jobRan) {
                // Queue is empty, exit gracefully on shared hosting or pause
                break;
            }
        }

        return $processed;
    }
}
