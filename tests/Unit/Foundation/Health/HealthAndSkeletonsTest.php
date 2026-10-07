<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Foundation\Health;

use Coleza\Foundation\Backup\BackupSkeleton;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Health\DatabaseHealthCheck;
use Coleza\Foundation\Health\HealthCheckResult;
use Coleza\Foundation\Health\HealthManager;
use Coleza\Foundation\Health\StorageHealthCheck;
use Coleza\Foundation\Installer\InstallerSkeleton;
use Coleza\Foundation\Storage\LocalStorage;
use PDO;
use PHPUnit\Framework\TestCase;

final class HealthAndSkeletonsTest extends TestCase
{
    private string $tempDir;
    private LocalStorage $storage;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/coleza_health_test_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0755, true);
        $this->storage = new LocalStorage($this->tempDir);

        $pdo = new PDO('sqlite::memory:');
        $this->connection = new Connection($pdo, 'sqlite');
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/*');
        if ($files) {
            foreach ($files as $f) {
                if (is_file($f)) {
                    unlink($f);
                }
            }
        }
        if (is_dir($this->tempDir)) {
            @rmdir($this->tempDir);
        }
    }

    public function testHealthManagerWithDatabaseAndStorage(): void
    {
        $manager = new HealthManager();
        $manager->register(new DatabaseHealthCheck($this->connection));
        $manager->register(new StorageHealthCheck($this->storage));

        $report = $manager->report();

        $this->assertSame('OK', $report['status']);
        $this->assertTrue($report['healthy']);
        $this->assertSame(HealthCheckResult::STATUS_HEALTHY, $report['checks']['database']['status']);
        $this->assertSame(HealthCheckResult::STATUS_HEALTHY, $report['checks']['storage']['status']);
    }

    public function testInstallerSkeletonPrerequisitesAndLock(): void
    {
        $installer = new InstallerSkeleton($this->connection);
        $prereqs = $installer->verifyPrerequisites();

        $this->assertTrue($prereqs['php_version']);
        $this->assertTrue($prereqs['pdo_available']);

        $lockPath = $this->tempDir . '/install.lock';
        $this->assertFalse($installer->isInstalled($lockPath));

        $installer->markInstalled($lockPath);
        $this->assertTrue($installer->isInstalled($lockPath));
    }

    public function testBackupSkeletonManifestAndStorage(): void
    {
        $backup = new BackupSkeleton($this->connection, $this->storage);
        $manifest = $backup->createManifest('full', ['users', 'invoices', 'services']);

        $this->assertStringStartsWith('bak_', $manifest['backup_id']);
        $this->assertSame('full', $manifest['type']);
        $this->assertCount(3, $manifest['tables']);

        $storedPath = $backup->storeManifest($manifest);
        $this->assertTrue($this->storage->exists($storedPath));
        $content = $this->storage->get($storedPath);
        $this->assertStringContainsString('bak_', (string) $content);
    }
}
