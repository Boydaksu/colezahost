<?php

declare(strict_types=1);

namespace Coleza\Foundation\Queue;

interface JobInterface
{
    /**
     * Execute the job logic.
     */
    public function handle(): void;

    /**
     * Target queue name (e.g. 'critical', 'billing', 'provisioning', 'default').
     */
    public function queue(): string;

    /**
     * Maximum retry attempts before moving to Dead Letter Queue (DLQ).
     */
    public function maxAttempts(): int;

    /**
     * Retry backoff in seconds.
     */
    public function backoffSeconds(): int;
}
