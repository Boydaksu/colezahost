<?php

declare(strict_types=1);

namespace Tests\Integration\SharedHost;

use Coleza\Domain\Backup\Storage\LocalBackupStorageAdapter;
use Coleza\Domain\Health\SystemDoctorService;
use Coleza\Foundation\Cache\DatabaseCache;
use Coleza\Foundation\Cache\FileCache;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Lock\DatabaseLock;
use Coleza\Foundation\Queue\DatabaseQueue;
use Coleza\Foundation\Queue\JobInterface;
use Coleza\Foundation\Runtime\SystemRequirements;
use Coleza\Foundation\Storage\LocalStorage;
use Coleza\Foundation\Worker\WorkerSupervisor;
use PDO;
use PHPUnit\Framework\TestCase;

final class SharedHostJob implements JobInterface
{
    public static int $processedCount = 0;

    public function handle(): void
    {
        self::$processedCount++;
    }

    public function queue(): string
    {
        return 'shared_host_queue';
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

/**
 * P18.6 Minimum Shared-Host Compatibility Matrix Test Suite.
 *
 * Verifies that Coleza Host operates 100% reliably in a constrained shared-host environment
 * (cPanel, DirectAdmin, Plesk) without external daemons (Redis, Memcached, Node.js, Docker, Supervisord).
 */
final class SharedHostCompatibilityMatrixTest extends TestCase
{
    private Connection $db;
    private string $tempDir;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db = new Connection($pdo);

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'colezahost_shared_host_test_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0755, true);

        SharedHostJob::$processedCount = 0;
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * SH-01: Runtime Environment & Extension Compatibility.
     * Verifies standard shared-host PHP 8.4 runtime and standard extensions without specialized C-extensions.
     */
    public function testRuntimeAndStandardExtensionsSatisfyRequirements(): void
    {
        $sysReq = new SystemRequirements();
        $errors = $sysReq->check();

        $this->assertEmpty($errors, 'Shared-host runtime requirements failed: ' . implode(', ', $errors));
        $this->assertTrue($sysReq->isSatisfied());

        // Verify standard shared-host modules
        $this->assertTrue(extension_loaded('pdo'), 'Missing standard pdo extension');
        $this->assertTrue(extension_loaded('mbstring'), 'Missing standard mbstring extension');
        $this->assertTrue(extension_loaded('json'), 'Missing standard json extension');
        $this->assertTrue(extension_loaded('filter'), 'Missing standard filter extension');
    }

    /**
     * SH-02: Zero-Daemon Queue Execution via DatabaseQueue.
     * Verifies reliable background queue operations purely over database tables without Redis/RabbitMQ.
     */
    public function testQueueOperatesReliablyViaDatabaseQueueWithoutRedis(): void
    {
        $queue = new DatabaseQueue($this->db);
        $this->assertSame(0, $queue->size('shared_host_queue'));

        // Push 5 jobs
        for ($i = 0; $i < 5; $i++) {
            $queue->push(new SharedHostJob());
        }

        $this->assertSame(5, $queue->size('shared_host_queue'));

        // Shared-host cron worker runs bounded batch
        $supervisor = new WorkerSupervisor(
            queue: $queue,
            memoryBudgetMb: 64,
            timeBudgetSeconds: 20
        );

        $processed = $supervisor->run(['shared_host_queue'], maxJobs: 5);

        $this->assertSame(5, $processed);
        $this->assertSame(5, SharedHostJob::$processedCount);
        $this->assertSame(0, $queue->size('shared_host_queue'));
    }

    /**
     * SH-03: Concurrency Synchronization via DatabaseLock.
     * Verifies atomic distributed locking via database tables without Redis Redlock or ZooKeeper.
     */
    public function testConcurrencyLockingOperatesViaDatabaseLockWithoutRedis(): void
    {
        $lock = new DatabaseLock($this->db);
        $lock->ensureLocksTable();

        $resource = 'shared_host_cron_execution';

        // Process 1 acquires lock
        $acquired = $lock->acquire($resource, ttlSeconds: 60, owner: 'cron_worker_1');
        $this->assertTrue($acquired);
        $this->assertTrue($lock->isLocked($resource));

        // Process 2 fails to acquire while held
        $acquired2 = $lock->acquire($resource, ttlSeconds: 60, owner: 'cron_worker_2');
        $this->assertFalse($acquired2);

        // Process 1 releases lock
        $released = $lock->release($resource, owner: 'cron_worker_1');
        $this->assertTrue($released);
        $this->assertFalse($lock->isLocked($resource));

        // Process 2 can now acquire
        $acquired3 = $lock->acquire($resource, ttlSeconds: 60, owner: 'cron_worker_2');
        $this->assertTrue($acquired3);
        $lock->release($resource, owner: 'cron_worker_2');
    }

    /**
     * SH-04: Caching via FileCache & DatabaseCache.
     * Verifies caching works out-of-the-box using filesystem and database without Memcached or Redis.
     */
    public function testCachingOperatesViaFileAndDatabaseAdapters(): void
    {
        // 1. FileCache
        $cacheDir = $this->tempDir . DIRECTORY_SEPARATOR . 'cache';
        $fileCache = new FileCache($cacheDir);

        $fileCache->set('site_settings', ['site_name' => 'Coleza Shared Host', 'currency' => 'TRY'], 300);
        $this->assertTrue($fileCache->has('site_settings'));
        $cached = $fileCache->get('site_settings');
        $this->assertSame('Coleza Shared Host', $cached['site_name'] ?? null);

        $fileCache->delete('site_settings');
        $this->assertFalse($fileCache->has('site_settings'));

        // 2. DatabaseCache
        $dbCache = new DatabaseCache($this->db);
        $dbCache->ensureCacheTable();

        $dbCache->set('theme_config', ['dark_mode' => true, 'primary_color' => '#1a56db'], 300);
        $this->assertTrue($dbCache->has('theme_config'));
        $dbCached = $dbCache->get('theme_config');
        $this->assertTrue($dbCached['dark_mode'] ?? false);

        $dbCache->delete('theme_config');
        $this->assertFalse($dbCache->has('theme_config'));
    }

    /**
     * SH-05: Local Storage File Isolation & Traversal Prevention.
     * Verifies LocalStorage handles files securely within shared hosting directory boundaries.
     */
    public function testLocalStorageOperatesSecurelyWithinDirectoryBoundaries(): void
    {
        $storageDir = $this->tempDir . DIRECTORY_SEPARATOR . 'storage';
        $storage = new LocalStorage($storageDir);

        // Write and read file
        $storage->put('invoices/INV-2026-001.pdf', 'PDF-MOCK-CONTENT-STREAM');
        $this->assertTrue($storage->exists('invoices/INV-2026-001.pdf'));
        $this->assertSame('PDF-MOCK-CONTENT-STREAM', $storage->get('invoices/INV-2026-001.pdf'));

        // Path traversal defense check
        $traversalPrevented = false;
        try {
            $storage->get('../../etc/passwd');
        } catch (\Throwable) {
            $traversalPrevented = true;
        }

        $this->assertTrue($traversalPrevented, 'Directory traversal path must be strictly rejected');
    }

    /**
     * SH-06: Cron Heartbeat and System Doctor Health Verification.
     * Verifies SystemDoctor diagnostic checks report HEALTHY on shared-host baseline adapter profile.
     */
    public function testSystemDoctorReportsHealthyOnSharedHostProfile(): void
    {
        // Seed cron_runs table for heartbeat
        $this->db->statement(
            'CREATE TABLE IF NOT EXISTS cron_runs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ran_at TIMESTAMP NOT NULL,
                duration_ms INT NOT NULL DEFAULT 50,
                tasks_executed INT NOT NULL DEFAULT 1,
                output_summary TEXT NULL
            )'
        );
        $this->db->insert('cron_runs', [
            'ran_at' => date('Y-m-d H:i:s'),
            'duration_ms' => 50,
            'tasks_executed' => 3,
            'output_summary' => 'cron pass',
        ]);

        $backupStorageDir = $this->tempDir . DIRECTORY_SEPARATOR . 'backups';
        mkdir($backupStorageDir, 0755, true);
        file_put_contents($backupStorageDir . '/backup_init.manifest.json', '{}');
        file_put_contents($backupStorageDir . '/backup_init.zip', 'content');
        $backupAdapter = new LocalBackupStorageAdapter($backupStorageDir);

        $doctor = new SystemDoctorService(
            db: $this->db,
            storageBasePath: $this->tempDir,
            backupStorage: $backupAdapter
        );

        $report = $doctor->diagnoseAll();

        $this->assertTrue($report->isPassing());

        $components = $report->getComponents();
        $this->assertArrayHasKey('database', $components);
        $this->assertArrayHasKey('storage', $components);
        $this->assertArrayHasKey('backup', $components);
        $this->assertArrayHasKey('cron', $components);

        $this->assertTrue($components['database']->isHealthy());
        $this->assertTrue($components['storage']->isHealthy());
        $this->assertTrue($components['backup']->isHealthy());
        $this->assertTrue($components['cron']->isHealthy());
    }
}
