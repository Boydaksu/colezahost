<?php

declare(strict_types=1);

namespace Coleza\Foundation\Scheduler;

use Closure;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Lock\LockInterface;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

final class Scheduler
{
    /** @var array<int, ScheduledTask> */
    private array $tasks = [];
    private string $heartbeatTable = 'cron_heartbeats';

    public function __construct(
        private ?Connection $db = null,
        private ?LockInterface $lock = null,
        private ?LoggerInterface $logger = null
    ) {
    }

    public function ensureHeartbeatTable(): void
    {
        if ($this->db === null) {
            return;
        }

        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                task_name VARCHAR(100) NOT NULL UNIQUE,
                last_started_at INT NOT NULL,
                last_finished_at INT NULL,
                status VARCHAR(20) NOT NULL,
                message TEXT NULL
            )',
            $this->heartbeatTable,
            $autoInc
        );

        $this->db->statement($sql);
    }

    /**
     * Schedule a new callback.
     *
     * @param Closure(): mixed $callback
     */
    public function call(Closure $callback, ?string $name = null): ScheduledTask
    {
        $task = new ScheduledTask($callback, $name);
        $this->tasks[] = $task;
        return $task;
    }

    /**
     * Get all registered tasks.
     *
     * @return array<int, ScheduledTask>
     */
    public function getTasks(): array
    {
        return $this->tasks;
    }

    /**
     * Run all tasks due at the given timestamp (or current time if null).
     *
     * @return int Count of tasks run
     */
    public function run(?DateTimeImmutable $currentTime = null): int
    {
        $now = $currentTime ?? new DateTimeImmutable('now');
        $this->ensureHeartbeatTable();
        $executedCount = 0;

        foreach ($this->tasks as $task) {
            if (!$task->isDue($now)) {
                continue;
            }

            $taskName = $task->getName();
            $startedAt = time();
            $this->recordHeartbeatStart($taskName, $startedAt);

            try {
                $ran = $task->run($this->lock, $this->logger);
                if ($ran) {
                    $executedCount++;
                    $this->recordHeartbeatFinish($taskName, time(), 'SUCCESS');
                } else {
                    $this->recordHeartbeatFinish($taskName, time(), 'SKIPPED_OVERLAP');
                }
            } catch (Throwable $e) {
                $this->recordHeartbeatFinish($taskName, time(), 'FAILED', $e->getMessage());
            }
        }

        return $executedCount;
    }

    /**
     * Inspect task heartbeat and detect missed runs or stuck tasks.
     *
     * @return array<string, mixed>|null
     */
    public function getHeartbeat(string $taskName): ?array
    {
        if ($this->db === null) {
            return null;
        }

        $this->ensureHeartbeatTable();
        return $this->db->selectOne(
            sprintf('SELECT * FROM %s WHERE task_name = :task_name', $this->heartbeatTable),
            ['task_name' => $taskName]
        );
    }

    private function recordHeartbeatStart(string $taskName, int $timestamp): void
    {
        if ($this->db === null) {
            return;
        }

        $existing = $this->getHeartbeat($taskName);
        if ($existing === null) {
            $this->db->insert($this->heartbeatTable, [
                'task_name' => $taskName,
                'last_started_at' => $timestamp,
                'last_finished_at' => null,
                'status' => 'RUNNING',
                'message' => null,
            ]);
        } else {
            $this->db->update(
                $this->heartbeatTable,
                [
                    'last_started_at' => $timestamp,
                    'status' => 'RUNNING',
                    'message' => null,
                ],
                'task_name = :task_name',
                ['task_name' => $taskName]
            );
        }
    }

    private function recordHeartbeatFinish(string $taskName, int $timestamp, string $status, ?string $message = null): void
    {
        if ($this->db === null) {
            return;
        }

        $this->db->update(
            $this->heartbeatTable,
            [
                'last_finished_at' => $timestamp,
                'status' => $status,
                'message' => $message,
            ],
            'task_name = :task_name',
            ['task_name' => $taskName]
        );
    }
}
