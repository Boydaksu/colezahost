<?php

declare(strict_types=1);

namespace Tests\Unit\Foundation\Queue;

use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Queue\DatabaseQueue;
use Coleza\Foundation\Queue\JobInterface;
use Coleza\Foundation\Queue\QueueWorker;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TestSuccessfulJob implements JobInterface
{
    public static bool $executed = false;

    public function handle(): void
    {
        self::$executed = true;
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
        return 5;
    }
}

final class TestFailingJob implements JobInterface
{
    public static int $runs = 0;

    public function handle(): void
    {
        self::$runs++;
        throw new RuntimeException('Intentional job failure');
    }

    public function queue(): string
    {
        return 'critical';
    }

    public function maxAttempts(): int
    {
        return 2;
    }

    public function backoffSeconds(): int
    {
        return 1;
    }
}

final class DatabaseQueueTest extends TestCase
{
    private Connection $connection;
    private DatabaseQueue $queue;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->connection = new Connection($pdo, 'sqlite');
        $this->queue = new DatabaseQueue($this->connection);
        TestSuccessfulJob::$executed = false;
        TestFailingJob::$runs = 0;
    }

    public function testPushAndPopSuccessfulJob(): void
    {
        $job = new TestSuccessfulJob();
        $id = $this->queue->push($job);

        $this->assertSame(1, $this->queue->size('default'));

        $popped = $this->queue->pop('default');
        $this->assertNotNull($popped);
        $this->assertSame('default', $popped->getQueue());
        $this->assertSame(1, $popped->getAttempts());
        $this->assertInstanceOf(TestSuccessfulJob::class, $popped->getJob());

        // While reserved, pop returns null
        $this->assertNull($this->queue->pop('default'));

        // Delete job
        $this->queue->delete($popped);
        $this->assertSame(0, $this->queue->size('default'));
    }

    public function testWorkerProcessesJobSuccessfully(): void
    {
        $job = new TestSuccessfulJob();
        $this->queue->push($job);

        $worker = new QueueWorker($this->queue);
        $processed = $worker->runNext('default');

        $this->assertTrue($processed);
        $this->assertTrue(TestSuccessfulJob::$executed);
        $this->assertSame(0, $this->queue->size('default'));
    }

    public function testRetryAndDeadLetterQueueOnFailure(): void
    {
        $job = new TestFailingJob();
        $this->queue->push($job);

        $worker = new QueueWorker($this->queue);

        // Attempt 1
        $worker->runNext('critical');
        $this->assertSame(1, TestFailingJob::$runs);
        $this->assertSame(1, $this->queue->size('critical'));
        $this->assertSame(0, $this->queue->failedSize('critical'));

        // Reset available_at so it can be picked up immediately for testing
        $this->connection->statement('UPDATE jobs SET available_at = 0, reserved_at = NULL WHERE queue = "critical"');

        // Attempt 2 (reaches maxAttempts = 2, moves to DLQ)
        $worker->runNext('critical');
        $this->assertSame(2, TestFailingJob::$runs);
        $this->assertSame(0, $this->queue->size('critical'));
        $this->assertSame(1, $this->queue->failedSize('critical'));
    }

    public function testWorkerPrioritizedQueues(): void
    {
        $this->queue->push(new TestSuccessfulJob()); // default queue
        $this->queue->push(new TestFailingJob()); // critical queue

        $worker = new QueueWorker($this->queue);
        // Process critical first
        $worker->work(['critical', 'default'], maxJobs: 1);

        $this->assertSame(1, TestFailingJob::$runs);
        $this->assertFalse(TestSuccessfulJob::$executed);

        // Next run should process default
        $worker->work(['default'], maxJobs: 1);
        $this->assertTrue(TestSuccessfulJob::$executed);
    }
}
