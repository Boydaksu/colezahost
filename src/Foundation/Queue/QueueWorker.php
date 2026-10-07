<?php

declare(strict_types=1);

namespace Coleza\Foundation\Queue;

use Psr\Log\LoggerInterface;
use Throwable;

final class QueueWorker
{
    public function __construct(
        private QueueInterface $queue,
        private ?LoggerInterface $logger = null
    ) {
    }

    /**
     * Process a single job from the given queue.
     *
     * @return bool True if a job was processed, false if queue was empty
     */
    public function runNext(string $queue = 'default'): bool
    {
        $job = $this->queue->pop($queue);
        if ($job === null) {
            return false;
        }

        try {
            $job->getJob()->handle();
            $this->queue->delete($job);
            $this->logger?->info(sprintf('Queue job [%s] on queue [%s] processed successfully.', $job->getId(), $queue));
            return true;
        } catch (Throwable $e) {
            $this->logger?->error(sprintf('Queue job [%s] failed: %s', $job->getId(), $e->getMessage()), [
                'exception' => $e,
                'job_id' => $job->getId(),
                'attempts' => $job->getAttempts(),
            ]);

            $this->queue->releaseOrFail($job, $e);
            return true;
        }
    }

    /**
     * Run worker loop across multiple prioritized queues.
     * Respects memory budget.
     *
     * @param array<int, string> $queues
     * @return int Number of processed jobs
     */
    public function work(array $queues = ['critical', 'default'], int $maxJobs = 0, int $memoryLimitMb = 128): int
    {
        $processed = 0;

        while (true) {
            if ($maxJobs > 0 && $processed >= $maxJobs) {
                break;
            }

            // Check memory budget
            if ((memory_get_usage(true) / 1024 / 1024) >= $memoryLimitMb) {
                $this->logger?->warning('Worker stopped: Memory limit exceeded.');
                break;
            }

            $jobRan = false;
            foreach ($queues as $q) {
                if ($this->runNext($q)) {
                    $jobRan = true;
                    $processed++;
                    break;
                }
            }

            if (!$jobRan) {
                // No jobs found across all queues
                break;
            }
        }

        return $processed;
    }
}
