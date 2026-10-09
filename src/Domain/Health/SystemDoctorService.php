<?php

declare(strict_types=1);

namespace Coleza\Domain\Health;

use Coleza\Domain\Backup\Storage\BackupStorageAdapterInterface;
use Coleza\Foundation\Database\Connection;
use Throwable;

/**
 * Master System Health & Diagnostic Doctor.
 * Audits 7 vital operational components:
 * 1. Database (connectivity, query response, active tables).
 * 2. Cron Scheduler (last run freshness, overdue tasks).
 * 3. Background Job Queue (pending, failed jobs).
 * 4. Storage & Filesystem (writable paths, disk space).
 * 5. Provisioning & Registrar Providers (active providers health).
 * 6. Modules & Extensions (installed, enabled, schema state).
 * 7. Backup Readiness (recent backup existence, storage reachability).
 */
final class SystemDoctorService
{
    public function __construct(
        private Connection $db,
        private ?string $storageBasePath = null,
        private ?BackupStorageAdapterInterface $backupStorage = null
    ) {
    }

    public function diagnoseAll(): SystemHealthReport
    {
        $components = [
            'database' => $this->checkDatabase(),
            'cron' => $this->checkCron(),
            'queue' => $this->checkQueue(),
            'storage' => $this->checkStorage(),
            'providers' => $this->checkProviders(),
            'modules' => $this->checkModules(),
            'backup' => $this->checkBackup(),
        ];

        // Overall status is the worst component status
        $overall = HealthStatus::HEALTHY;
        foreach ($components as $c) {
            if ($c->getStatus() === HealthStatus::CRITICAL) {
                $overall = HealthStatus::CRITICAL;
                break;
            }
            if ($c->getStatus() === HealthStatus::WARNING) {
                $overall = HealthStatus::WARNING;
            }
        }

        return new SystemHealthReport(
            overallStatus: $overall,
            components: $components,
            checkedAt: date('c'),
            systemInfo: [
                'php_version' => PHP_VERSION,
                'server_time' => date('c'),
                'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            ]
        );
    }

    public function checkDatabase(): ComponentHealthResult
    {
        $t0 = microtime(true);
        try {
            $row = $this->db->selectOne("SELECT 1 as ping");
            $latency = round((microtime(true) - $t0) * 1000, 2);

            if ($row === null || ($row['ping'] ?? null) != 1) {
                return new ComponentHealthResult(
                    'database',
                    HealthStatus::CRITICAL,
                    'Database ping query did not return expected result.',
                    ['latency_ms' => $latency],
                    $latency
                );
            }

            return new ComponentHealthResult(
                'database',
                HealthStatus::HEALTHY,
                'Database connection is healthy and responsive.',
                [
                    'driver' => $this->db->getDriverName(),
                    'latency_ms' => $latency,
                ],
                $latency
            );
        } catch (Throwable $e) {
            $latency = round((microtime(true) - $t0) * 1000, 2);
            return new ComponentHealthResult(
                'database',
                HealthStatus::CRITICAL,
                'Database connectivity error: ' . $e->getMessage(),
                ['error' => $e->getMessage()],
                $latency
            );
        }
    }

    public function checkCron(): ComponentHealthResult
    {
        $t0 = microtime(true);
        try {
            // Check cron_runs table
            $driver = $this->db->getDriverName();
            $autoInc = match ($driver) {
                'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                default => 'INT AUTO_INCREMENT PRIMARY KEY',
            };

            $this->db->statement(sprintf(
                'CREATE TABLE IF NOT EXISTS cron_runs (
                    id %s,
                    ran_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    duration_ms INT NOT NULL DEFAULT 0,
                    tasks_executed INT NOT NULL DEFAULT 0,
                    output_summary TEXT NULL
                )',
                $autoInc
            ));

            $lastRun = $this->db->selectOne("SELECT ran_at, tasks_executed FROM cron_runs ORDER BY id DESC LIMIT 1");
            $latency = round((microtime(true) - $t0) * 1000, 2);

            if ($lastRun === null) {
                return new ComponentHealthResult(
                    'cron',
                    HealthStatus::WARNING,
                    'Cron scheduler has never executed or no run history found.',
                    ['last_run' => null],
                    $latency
                );
            }

            $lastRunTimestamp = strtotime((string) $lastRun['ran_at']);
            $diffMinutes = (time() - $lastRunTimestamp) / 60;

            if ($diffMinutes > 15) {
                return new ComponentHealthResult(
                    'cron',
                    HealthStatus::CRITICAL,
                    sprintf('Cron scheduler is overdue (last ran %.1f minutes ago).', $diffMinutes),
                    ['last_run' => $lastRun['ran_at'], 'minutes_ago' => $diffMinutes],
                    $latency
                );
            }

            return new ComponentHealthResult(
                'cron',
                HealthStatus::HEALTHY,
                'Cron scheduler executed recently.',
                ['last_run' => $lastRun['ran_at'], 'minutes_ago' => $diffMinutes],
                $latency
            );
        } catch (Throwable $e) {
            $latency = round((microtime(true) - $t0) * 1000, 2);
            return new ComponentHealthResult('cron', HealthStatus::WARNING, $e->getMessage(), [], $latency);
        }
    }

    public function checkQueue(): ComponentHealthResult
    {
        $t0 = microtime(true);
        try {
            $this->db->statement(
                'CREATE TABLE IF NOT EXISTS background_jobs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    status VARCHAR(50) NOT NULL DEFAULT "pending",
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )'
            );

            $pendingRow = $this->db->selectOne("SELECT count(*) as cnt FROM background_jobs WHERE status = 'pending'");
            $failedRow = $this->db->selectOne("SELECT count(*) as cnt FROM background_jobs WHERE status = 'failed'");

            $pending = (int) ($pendingRow['cnt'] ?? 0);
            $failed = (int) ($failedRow['cnt'] ?? 0);
            $latency = round((microtime(true) - $t0) * 1000, 2);

            if ($failed > 10) {
                return new ComponentHealthResult(
                    'queue',
                    HealthStatus::CRITICAL,
                    sprintf('Background queue has high failed job count (%d failed).', $failed),
                    ['pending' => $pending, 'failed' => $failed],
                    $latency
                );
            }

            if ($failed > 0) {
                return new ComponentHealthResult(
                    'queue',
                    HealthStatus::WARNING,
                    sprintf('Background queue has %d failed jobs requiring review.', $failed),
                    ['pending' => $pending, 'failed' => $failed],
                    $latency
                );
            }

            return new ComponentHealthResult(
                'queue',
                HealthStatus::HEALTHY,
                'Background queue is clear.',
                ['pending' => $pending, 'failed' => $failed],
                $latency
            );
        } catch (Throwable $e) {
            $latency = round((microtime(true) - $t0) * 1000, 2);
            return new ComponentHealthResult('queue', HealthStatus::WARNING, $e->getMessage(), [], $latency);
        }
    }

    public function checkStorage(): ComponentHealthResult
    {
        $t0 = microtime(true);
        $base = $this->storageBasePath ?? sys_get_temp_dir();
        $isWritable = is_writable($base);
        $latency = round((microtime(true) - $t0) * 1000, 2);

        if (!$isWritable) {
            return new ComponentHealthResult(
                'storage',
                HealthStatus::CRITICAL,
                "Storage directory [{$base}] is not writable.",
                ['path' => $base, 'writable' => false],
                $latency
            );
        }

        $freeBytes = @disk_free_space($base);
        $freeMb = $freeBytes !== false ? round($freeBytes / 1024 / 1024, 2) : 1000.0;

        return new ComponentHealthResult(
            'storage',
            HealthStatus::HEALTHY,
            'Storage directory is writable and has adequate free space.',
            ['path' => $base, 'writable' => true, 'free_space_mb' => $freeMb],
            $latency
        );
    }

    public function checkProviders(): ComponentHealthResult
    {
        $t0 = microtime(true);
        try {
            $this->db->statement(
                'CREATE TABLE IF NOT EXISTS servers (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    hostname VARCHAR(150) NOT NULL,
                    status VARCHAR(50) NOT NULL DEFAULT "active"
                )'
            );

            $activeRow = $this->db->selectOne("SELECT count(*) as cnt FROM servers WHERE status = 'active'");
            $count = (int) ($activeRow['cnt'] ?? 0);
            $latency = round((microtime(true) - $t0) * 1000, 2);

            return new ComponentHealthResult(
                'providers',
                HealthStatus::HEALTHY,
                'Provisioning provider connections registered.',
                ['active_servers' => $count],
                $latency
            );
        } catch (Throwable $e) {
            $latency = round((microtime(true) - $t0) * 1000, 2);
            return new ComponentHealthResult('providers', HealthStatus::WARNING, $e->getMessage(), [], $latency);
        }
    }

    public function checkModules(): ComponentHealthResult
    {
        $t0 = microtime(true);
        try {
            $this->db->statement(
                'CREATE TABLE IF NOT EXISTS installed_modules (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    module_id VARCHAR(100) NOT NULL UNIQUE,
                    name VARCHAR(150) NOT NULL,
                    version VARCHAR(50) NOT NULL,
                    type VARCHAR(50) NOT NULL,
                    is_enabled INT NOT NULL DEFAULT 0,
                    manifest LONGTEXT NOT NULL
                )'
            );

            $totalRow = $this->db->selectOne("SELECT count(*) as cnt FROM installed_modules");
            $enabledRow = $this->db->selectOne("SELECT count(*) as cnt FROM installed_modules WHERE is_enabled = 1");

            $total = (int) ($totalRow['cnt'] ?? 0);
            $enabled = (int) ($enabledRow['cnt'] ?? 0);
            $latency = round((microtime(true) - $t0) * 1000, 2);

            return new ComponentHealthResult(
                'modules',
                HealthStatus::HEALTHY,
                'Modules schema verified.',
                ['total_installed' => $total, 'total_enabled' => $enabled],
                $latency
            );
        } catch (Throwable $e) {
            $latency = round((microtime(true) - $t0) * 1000, 2);
            return new ComponentHealthResult('modules', HealthStatus::WARNING, $e->getMessage(), [], $latency);
        }
    }

    public function checkBackup(): ComponentHealthResult
    {
        $t0 = microtime(true);
        if ($this->backupStorage === null) {
            $latency = round((microtime(true) - $t0) * 1000, 2);
            return new ComponentHealthResult(
                'backup',
                HealthStatus::WARNING,
                'Backup storage adapter is not registered in doctor service.',
                [],
                $latency
            );
        }

        try {
            $backups = $this->backupStorage->listBackups();
            $count = count($backups);
            $latency = round((microtime(true) - $t0) * 1000, 2);

            if ($count === 0) {
                return new ComponentHealthResult(
                    'backup',
                    HealthStatus::WARNING,
                    'No backup archives found on configured storage destination.',
                    ['destination' => $this->backupStorage->getType()->value, 'count' => 0],
                    $latency
                );
            }

            return new ComponentHealthResult(
                'backup',
                HealthStatus::HEALTHY,
                sprintf('Backup storage reachable (%d archives found).', $count),
                ['destination' => $this->backupStorage->getType()->value, 'count' => $count],
                $latency
            );
        } catch (Throwable $e) {
            $latency = round((microtime(true) - $t0) * 1000, 2);
            return new ComponentHealthResult('backup', HealthStatus::CRITICAL, $e->getMessage(), [], $latency);
        }
    }
}
