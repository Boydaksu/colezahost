<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Health;

use Coleza\Domain\Backup\Storage\LocalBackupStorageAdapter;
use Coleza\Domain\Health\ComponentHealthResult;
use Coleza\Domain\Health\HealthStatus;
use Coleza\Domain\Health\SystemDoctorService;
use Coleza\Domain\Health\SystemHealthReport;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class SystemDoctorHealthTest extends TestCase
{
    private Connection $db;
    private string $tempStorageDir;
    private string $tempBackupDir;

    protected function setUp(): void
    {
        parent::setUp();

        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->tempStorageDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'health_storage_' . uniqid();
        $this->tempBackupDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'health_backup_' . uniqid();

        mkdir($this->tempStorageDir, 0777, true);
        mkdir($this->tempBackupDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempStorageDir);
        $this->removeDirectory($this->tempBackupDir);

        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testDatabaseComponentHealthPasses(): void
    {
        $doctor = new SystemDoctorService($this->db);
        $result = $doctor->checkDatabase();

        $this->assertSame(HealthStatus::HEALTHY, $result->getStatus());
        $this->assertTrue($result->isHealthy());
        $this->assertArrayHasKey('latency_ms', $result->getMetrics());
    }

    public function testCronOverdueDetection(): void
    {
        $doctor = new SystemDoctorService($this->db);

        // 1. Never ran
        $warnResult = $doctor->checkCron();
        $this->assertSame(HealthStatus::WARNING, $warnResult->getStatus());

        // 2. Ran 2 hours ago (overdue)
        $pastDate = date('Y-m-d H:i:s', strtotime('-120 minutes'));
        $this->db->statement("INSERT INTO cron_runs (ran_at, tasks_executed) VALUES (?, 5)", [$pastDate]);

        $critResult = $doctor->checkCron();
        $this->assertSame(HealthStatus::CRITICAL, $critResult->getStatus());
        $this->assertStringContainsString('overdue', $critResult->getMessage());

        // 3. Ran 2 minutes ago (fresh)
        $freshDate = date('Y-m-d H:i:s', strtotime('-2 minutes'));
        $this->db->statement("INSERT INTO cron_runs (ran_at, tasks_executed) VALUES (?, 10)", [$freshDate]);

        $healthyResult = $doctor->checkCron();
        $this->assertSame(HealthStatus::HEALTHY, $healthyResult->getStatus());
    }

    public function testQueueFailedJobThresholds(): void
    {
        $doctor = new SystemDoctorService($this->db);

        // Clean queue
        $res = $doctor->checkQueue();
        $this->assertSame(HealthStatus::HEALTHY, $res->getStatus());

        // 1 failed job -> Warning
        $this->db->statement("INSERT INTO background_jobs (status) VALUES ('failed')");
        $warnRes = $doctor->checkQueue();
        $this->assertSame(HealthStatus::WARNING, $warnRes->getStatus());

        // 15 failed jobs -> Critical
        for ($i = 0; $i < 15; $i++) {
            $this->db->statement("INSERT INTO background_jobs (status) VALUES ('failed')");
        }
        $critRes = $doctor->checkQueue();
        $this->assertSame(HealthStatus::CRITICAL, $critRes->getStatus());
    }

    public function testComprehensiveDiagnoseAllAudits7Components(): void
    {
        $backupAdapter = new LocalBackupStorageAdapter($this->tempBackupDir);
        // Seed a sample backup archive
        file_put_contents($this->tempBackupDir . '/backup_init.manifest.json', '{}');
        file_put_contents($this->tempBackupDir . '/backup_init.zip', 'content');

        // Seed fresh cron
        $this->db->statement(
            "CREATE TABLE IF NOT EXISTS cron_runs (id INTEGER PRIMARY KEY AUTOINCREMENT, ran_at TIMESTAMP, duration_ms INT, tasks_executed INT, output_summary TEXT)"
        );
        $this->db->statement("INSERT INTO cron_runs (ran_at, tasks_executed) VALUES (?, 3)", [date('Y-m-d H:i:s')]);

        $doctor = new SystemDoctorService(
            db: $this->db,
            storageBasePath: $this->tempStorageDir,
            backupStorage: $backupAdapter
        );

        $report = $doctor->diagnoseAll();

        $this->assertInstanceOf(SystemHealthReport::class, $report);
        $this->assertTrue($report->isPassing());

        $components = $report->getComponents();
        $this->assertCount(7, $components);
        $this->assertArrayHasKey('database', $components);
        $this->assertArrayHasKey('cron', $components);
        $this->assertArrayHasKey('queue', $components);
        $this->assertArrayHasKey('storage', $components);
        $this->assertArrayHasKey('providers', $components);
        $this->assertArrayHasKey('modules', $components);
        $this->assertArrayHasKey('backup', $components);

        $reportArray = $report->toArray();
        $this->assertTrue($reportArray['is_passing']);
        $this->assertArrayHasKey('system_info', $reportArray);
    }
}
