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
    private bool $initialized = false;

    public function __construct(private Connection $db, private ?QueuePayloadStoreInterface $payloadStore = null)
    {
    }

    public function ensureTables(): void
    {
        if ($this->initialized) { return; }
        if ($this->db->getDriverName() === 'mysql' && $this->db->inTransaction()) {
            if (!\Coleza\Foundation\Database\PdoSchema::useInstalledSchema($this->db, self::schemaRequirements())) { throw new RuntimeException('Initialize queue schema before starting an application transaction.'); }
            $this->initialized = true;
            return;
        }
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
                reservation_token VARCHAR(64) NULL,
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
        $this->initialized = true;
    }

    public static function schemaRequirements(): array
    {
        return ['jobs' => ['id', 'queue', 'payload', 'attempts', 'reserved_at', 'reservation_token', 'available_at'],
            'failed_jobs' => ['id', 'queue', 'payload', 'exception']];
    }
    private function payloads(): QueuePayloadStoreInterface
    {
        return $this->payloadStore ?? throw new RuntimeException('Configure encrypted queue payload storage before processing jobs.');
    }

    /** Prepare outside caller transactions; installed dependencies are checked without DDL inside them. */
    public function prepareStorage(): void
    {
        $this->payloads()->prepareKey(); $this->ensureTables(); $this->payloads()->prepare();
    }

    public function push(JobInterface $job, int $delaySeconds = 0): int|string
    {
        $this->prepareStorage();
        $queue = $job->queue();
        if ($queue === '' || strlen($queue) > 50 || $delaySeconds < 0 || $delaySeconds > 2147483647 - time()) {
            throw new \InvalidArgumentException('Invalid queue or delay.');
        }
        $payload = serialize($job);
        return $this->db->transaction(function () use ($queue, $payload, $delaySeconds): int|string {
            $this->payloads()->guard();
            $id = $this->db->insert($this->jobsTable, ['queue' => $queue, 'payload' => '', 'attempts' => 0,
                'reserved_at' => null, 'reservation_token' => null, 'available_at' => time() + $delaySeconds]);
            $ref = $this->payloads()->writeNew($this->jobsTable, ['id' => $id, 'queue' => $queue], $payload);
            $this->db->update($this->jobsTable, ['payload' => $ref], 'id = :id', ['id' => $id]);
            return $id;
        });
    }

    public function pop(string $queue = 'default'): ?QueueJob
    {
        $this->prepareStorage(); $now = time(); $expirationCutoff = $now - 300;
        return $this->db->transaction(function (Connection $db) use ($queue, $now, $expirationCutoff): ?QueueJob {
            // Same ordering as application-key rotation: binding, queue owner, Vault.
            $this->payloads()->guard();
            $row = $db->selectOne('SELECT * FROM jobs WHERE queue = :q AND available_at <= :now
                AND (reserved_at IS NULL OR reserved_at < :cutoff) ORDER BY id ASC LIMIT 1'
                . ($db->getDriverName() === 'mysql' ? ' FOR UPDATE SKIP LOCKED' : ''),
                ['q' => $queue, 'now' => $now, 'cutoff' => $expirationCutoff]);
            if ($row === null) { return null; }
            $payload = $this->payloads()->read($this->jobsTable, $row);
            $token = bin2hex(random_bytes(32));
            $claimed = $db->affectingStatement('UPDATE jobs SET reserved_at = ?, reservation_token = ?, attempts = attempts + 1
                WHERE id = ? AND (reserved_at IS NULL OR reserved_at < ?)', [$now, $token, $row['id'], $expirationCutoff]);
            if ($claimed !== 1) { throw new RuntimeException('Queue reservation was lost.'); }
            try { $instance = unserialize($payload); }
            catch (Throwable) { throw new RuntimeException('Queue job could not be hydrated.'); }
            if (!$instance instanceof JobInterface) { throw new RuntimeException('Queue payload does not describe an installed job.'); }
            return new QueueJob($row['id'], $queue, $instance, (int) $row['attempts'] + 1, $token);
        });
    }

    private function owned(QueueJob $job): ?array
    {
        return $this->db->selectOne('SELECT * FROM jobs WHERE id = ? AND reservation_token = ? AND queue = ?' . $this->db->forUpdate(),
            [$job->getId(), $job->getReservationToken(), $job->getQueue()]);
    }

    public function delete(QueueJob $job): void
    {
        $this->prepareStorage();
        $this->db->transaction(function () use ($job): void {
            $this->payloads()->guard(); $row = $this->owned($job);
            if ($row === null) { return; }
            $this->payloads()->read($this->jobsTable, $row);
            $this->payloads()->delete($this->jobsTable, $row);
            $this->db->delete($this->jobsTable, 'id = :id', ['id' => $row['id']]);
        });
    }

    public function releaseOrFail(QueueJob $job, Throwable $exception): void
    {
        $this->prepareStorage();
        $this->db->transaction(function () use ($job, $exception): void {
            $this->payloads()->guard(); $row = $this->owned($job);
            if ($row === null) { return; }
            $this->payloads()->read($this->jobsTable, $row);
            $instance = $job->getJob();
            if ((int) $row['attempts'] >= $instance->maxAttempts()) {
                $id = $this->db->insert($this->failedJobsTable, ['queue' => $row['queue'], 'payload' => '', 'exception' => get_class($exception)]);
                $ref = $this->payloads()->writeNew($this->failedJobsTable, ['id' => $id, 'queue' => $row['queue']], serialize($instance));
                $this->db->update($this->failedJobsTable, ['payload' => $ref], 'id = :id', ['id' => $id]);
                $this->payloads()->delete($this->jobsTable, $row);
                $this->db->delete($this->jobsTable, 'id = :id', ['id' => $row['id']]);
                return;
            }
            $backoff = $instance->backoffSeconds() * (int) $row['attempts'];
            if ($backoff < 0 || $backoff > 2147483647 - time()) { throw new RuntimeException('Invalid queue retry delay.'); }
            $this->db->update($this->jobsTable, ['reserved_at' => null, 'reservation_token' => null,
                'available_at' => time() + $backoff], 'id = :id', ['id' => $row['id']]);
        });
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
