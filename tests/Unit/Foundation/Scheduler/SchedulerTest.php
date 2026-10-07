<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Foundation\Scheduler;

use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Lock\DatabaseLock;
use Coleza\Foundation\Scheduler\Scheduler;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SchedulerTest extends TestCase
{
    private Connection $connection;
    private DatabaseLock $lock;
    private Scheduler $scheduler;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->connection = new Connection($pdo, 'sqlite');
        $this->lock = new DatabaseLock($this->connection);
        $this->scheduler = new Scheduler($this->connection, $this->lock);
    }

    public function testRunsDueTasksAndRecordsHeartbeat(): void
    {
        $executed = false;
        $this->scheduler->call(function () use (&$executed): void {
            $executed = true;
        }, 'daily_backup')->dailyAt('04:00');

        // Not due at 03:59
        $ranNotDue = $this->scheduler->run(new DateTimeImmutable('2026-10-07 03:59:00'));
        $this->assertSame(0, $ranNotDue);
        $this->assertFalse($executed);

        // Due at 04:00
        $ranDue = $this->scheduler->run(new DateTimeImmutable('2026-10-07 04:00:00'));
        $this->assertSame(1, $ranDue);
        $this->assertTrue($executed);

        // Verify Heartbeat recorded
        $heartbeat = $this->scheduler->getHeartbeat('daily_backup');
        $this->assertNotNull($heartbeat);
        $this->assertSame('daily_backup', $heartbeat['task_name']);
        $this->assertSame('SUCCESS', $heartbeat['status']);
        $this->assertNotNull($heartbeat['last_finished_at']);
    }

    public function testTaskWithoutOverlappingSkipsWhenLocked(): void
    {
        $executions = 0;
        $this->scheduler->call(function () use (&$executions): void {
            $executions++;
        }, 'sync_orders')->everyMinute()->withoutOverlapping(60);

        // Pre-acquire lock to simulate currently running instance
        $this->lock->acquire('scheduler:lock:sync_orders', 60);

        $ran = $this->scheduler->run(new DateTimeImmutable('2026-10-07 10:00:00'));
        $this->assertSame(0, $ran);
        $this->assertSame(0, $executions);

        $heartbeat = $this->scheduler->getHeartbeat('sync_orders');
        $this->assertSame('SKIPPED_OVERLAP', $heartbeat['status']);

        // Release lock and run again
        $this->lock->release('scheduler:lock:sync_orders');
        $ranSecond = $this->scheduler->run(new DateTimeImmutable('2026-10-07 10:01:00'));
        $this->assertSame(1, $ranSecond);
        $this->assertSame(1, $executions);

        $heartbeatAfter = $this->scheduler->getHeartbeat('sync_orders');
        $this->assertSame('SUCCESS', $heartbeatAfter['status']);
    }

    public function testHeartbeatRecordsFailureOnException(): void
    {
        $this->scheduler->call(function (): void {
            throw new RuntimeException('External service timeout');
        }, 'failing_task')->everyMinute();

        $this->scheduler->run(new DateTimeImmutable('2026-10-07 12:00:00'));

        $heartbeat = $this->scheduler->getHeartbeat('failing_task');
        $this->assertNotNull($heartbeat);
        $this->assertSame('FAILED', $heartbeat['status']);
        $this->assertStringContainsString('External service timeout', (string) $heartbeat['message']);
    }

    public function testFrequencies(): void
    {
        $task = $this->scheduler->call(fn () => null, 'cadence_test');

        $task->everyFiveMinutes();
        $this->assertTrue($task->isDue(new DateTimeImmutable('2026-10-07 10:05:00')));
        $this->assertFalse($task->isDue(new DateTimeImmutable('2026-10-07 10:06:00')));

        $task->hourly();
        $this->assertTrue($task->isDue(new DateTimeImmutable('2026-10-07 11:00:00')));
        $this->assertFalse($task->isDue(new DateTimeImmutable('2026-10-07 11:01:00')));
    }
}
