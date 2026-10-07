<?php

declare(strict_types=1);

namespace Coleza\Foundation\Queue;

interface QueueInterface
{
    /**
     * Push a new job onto the queue.
     */
    public function push(JobInterface $job, int $delaySeconds = 0): int|string;

    /**
     * Pop the next available job off the queue.
     */
    public function pop(string $queue = 'default'): ?QueueJob;

    /**
     * Delete a job from the queue after successful execution.
     */
    public function delete(QueueJob $job): void;

    /**
     * Release a failed job back onto the queue with a delay, or move to DLQ if max attempts reached.
     */
    public function releaseOrFail(QueueJob $job, \Throwable $exception): void;

    /**
     * Get count of pending jobs in queue.
     */
    public function size(string $queue = 'default'): int;
}
