<?php

declare(strict_types=1);

namespace Coleza\Foundation\Queue;

use Coleza\Foundation\Database\Connection;
use RuntimeException;
use Throwable;

final class DatabaseQueue implements QueueInterface
{
    private string $jobsTable = 'jobs';
    private string $failedJobsTable = 'failed_jobs';

    public function __construct(private Connection $db)
    {
    }

    public function ensureTables(): void
    {
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        // Jobs table
        $sqlJobs = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                queue VARCHAR(50) NOT NULL,
                payload LONGTEXT NOT NULL,
                attempts INT NOT NULL DEFAULT 0,
                reserved_at INT NULL,
                available_at INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->jobsTable,
            $autoInc
        );
        $this->db->statement($sqlJobs);

        // Failed jobs (DLQ) table
        $sqlFailed = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                queue VARCHAR(50) NOT NULL,
                payload LONGTEXT NOT NULL,
                exception LONGTEXT NOT NULL,
                failed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->failedJobsTable,
            $autoInc
        );
        $this->db->statement($sqlFailed);
    }

    public function push(JobInterface $job, int $delaySeconds = 0): int|string
    {
        $this->ensureTables();
        $now = time();
        $availableAt = $now + $delaySeconds;
        $queue = $job->queue();
        $payload = serialize($job);

        return $this->db->insert($this->jobsTable, [
            'queue' => $queue,
            'payload' => $payload,
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $availableAt,
        ]);
    }

    public function pop(string $queue = 'default'): ?QueueJob
    {
        $this->ensureTables();
        $now = time();
        $expirationCutoff = $now - 300; // 5 minutes reservation timeout

        return $this->db->transaction(function (Connection $db) use ($queue, $now, $expirationCutoff): ?QueueJob {
            $row = $db->selectOne(
                sprintf(
                    'SELECT id, payload, attempts FROM %s 
                     WHERE queue = :q 
                       AND available_at <= :now 
                       AND (reserved_at IS NULL OR reserved_at < :cutoff)
                     ORDER BY id ASC',
                    $this->jobsTable
                ),
                [
                    'q' => $queue,
                    'now' => $now,
                    'cutoff' => $expirationCutoff,
                ]
            );

            if ($row === null) {
                return null;
            }

            $id = $row['id'];
            $attempts = (int) $row['attempts'] + 1;

            $db->update(
                $this->jobsTable,
                [
                    'reserved_at' => $now,
                    'attempts' => $attempts,
                ],
                'id = :where_id',
                ['where_id' => $id]
            );

            $jobInstance = @unserialize((string) $row['payload']);
            if (!$jobInstance instanceof JobInterface) {
                // Malformed job, immediately purge
                $db->delete($this->jobsTable, 'id = :id', ['id' => $id]);
                return null;
            }

            return new QueueJob($id, $queue, $jobInstance, $attempts);
        });
    }

    public function delete(QueueJob $job): void
    {
        $this->ensureTables();
        $this->db->delete($this->jobsTable, 'id = :id', ['id' => $job->getId()]);
    }

    public function releaseOrFail(QueueJob $job, Throwable $exception): void
    {
        $this->ensureTables();
        $jobInstance = $job->getJob();

        if ($job->getAttempts() >= $jobInstance->maxAttempts()) {
            // Move to Dead Letter Queue (DLQ)
            $this->db->transaction(function (Connection $db) use ($job, $exception): void {
                $db->insert($this->failedJobsTable, [
                    'queue' => $job->getQueue(),
                    'payload' => serialize($job->getJob()),
                    'exception' => sprintf(
                        "[%s] %s in %s:%d\nStack trace:\n%s",
                        get_class($exception),
                        $exception->getMessage(),
                        $exception->getFile(),
                        $exception->getLine(),
                        $exception->getTraceAsString()
                    ),
                ]);

                $db->delete($this->jobsTable, 'id = :id', ['id' => $job->getId()]);
            });
            return;
        }

        // Release with backoff delay
        $backoff = $jobInstance->backoffSeconds() * $job->getAttempts(); // linear or exponential
        $availableAt = time() + $backoff;

        $this->db->update(
            $this->jobsTable,
            [
                'reserved_at' => null,
                'available_at' => $availableAt,
            ],
            'id = :where_id',
            ['where_id' => $job->getId()]
        );
    }

    public function size(string $queue = 'default'): int
    {
        $this->ensureTables();
        $row = $this->db->selectOne(
            sprintf('SELECT COUNT(*) as cnt FROM %s WHERE queue = :q', $this->jobsTable),
            ['q' => $queue]
        );

        return $row ? (int) $row['cnt'] : 0;
    }

    public function failedSize(string $queue = 'default'): int
    {
        $this->ensureTables();
        $row = $this->db->selectOne(
            sprintf('SELECT COUNT(*) as cnt FROM %s WHERE queue = :q', $this->failedJobsTable),
            ['q' => $queue]
        );

        return $row ? (int) $row['cnt'] : 0;
    }
}
