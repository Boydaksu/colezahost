<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Foundation\Worker;

use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Queue\DatabaseQueue;
use Coleza\Foundation\Queue\JobInterface;
use Coleza\Foundation\Worker\WorkerSupervisor;
use PDO;
use PHPUnit\Framework\TestCase;

final class SimpleTaskJob implements JobInterface
{
    public static int $handledCount = 0;

    public function handle(): void
    {
        self::$handledCount++;
    }

    public function queue(): string
    {
        return 'default';
    }

    public function maxAttempts(): int
    {
        return 3;
    }

    public function backoffSeconds(): int
    {
        return 0;
    }
}

final class WorkerSupervisorTest extends TestCase
{
    private Connection $connection;
    private DatabaseQueue $queue;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->connection = new Connection($pdo, 'sqlite');
        $this->queue = new DatabaseQueue($this->connection);
        SimpleTaskJob::$handledCount = 0;
    }

    public function testRunsJobsUntilMaxLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->queue->push(new SimpleTaskJob());
        }

        $supervisor = new WorkerSupervisor($this->queue, null, memoryBudgetMb: 256, timeBudgetSeconds: 60);
        $processed = $supervisor->run(['default'], maxJobs: 3);

        $this->assertSame(3, $processed);
        $this->assertSame(3, SimpleTaskJob::$handledCount);
        $this->assertSame(2, $this->queue->size('default'));
    }

    public function testStopsGracefullyWhenStopRequested(): void
    {
        $this->queue->push(new SimpleTaskJob());
        $supervisor = new WorkerSupervisor($this->queue);

        $supervisor->stop();
        $this->assertTrue($supervisor->isStopped());

        $processed = $supervisor->run(['default']);
        $this->assertSame(0, $processed);
        $this->assertSame(0, SimpleTaskJob::$handledCount);
    }

    public function testStopsWhenTimeBudgetExceeded(): void
    {
        $this->queue->push(new SimpleTaskJob());
        // Set time budget to 0 seconds so it immediately terminates after starting
        $supervisor = new WorkerSupervisor($this->queue, null, memoryBudgetMb: 256, timeBudgetSeconds: 0);

        // limitsExceeded will evaluate elapsed >= 0 as true
        $processed = $supervisor->run(['default']);
        $this->assertSame(0, $processed);
    }

    public function testStopsWhenMemoryBudgetExceeded(): void
    {
        $this->queue->push(new SimpleTaskJob());
        // Set an impossibly low memory budget (e.g. 1 MB)
        $supervisor = new WorkerSupervisor($this->queue, null, memoryBudgetMb: 1, timeBudgetSeconds: 60);

        $processed = $supervisor->run(['default']);
        $this->assertSame(0, $processed);
    }
}
