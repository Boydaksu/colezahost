<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\OperationalMode;

use Coleza\Domain\OperationalMode\OperationalMode;
use Coleza\Domain\OperationalMode\OperationalModeManager;
use Coleza\Domain\OperationalMode\OperationModeRestrictionException;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class OperationalModeTest extends TestCase
{
    private Connection $db;
    private string $tempLockFile;

    protected function setUp(): void
    {
        parent::setUp();

        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->tempLockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_op_mode_' . uniqid() . '.lock';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempLockFile)) {
            @unlink($this->tempLockFile);
        }

        parent::tearDown();
    }

    public function testDefaultModeIsNormal(): void
    {
        $manager = new OperationalModeManager($this->db, $this->tempLockFile);

        $this->assertSame(OperationalMode::NORMAL, $manager->getCurrentMode());
        $status = $manager->getModeStatus();
        $this->assertSame('normal', $status['mode']);
        $this->assertTrue($status['allows_customer_access']);
        $this->assertTrue($status['allows_writes']);
        $this->assertTrue($status['allows_cron']);
    }

    public function testReadOnlyModeBlocksWriteOperations(): void
    {
        $manager = new OperationalModeManager($this->db, $this->tempLockFile);
        $manager->transitionTo(OperationalMode::READ_ONLY, 'Database index rebuild in progress');

        $this->assertSame(OperationalMode::READ_ONLY, $manager->getCurrentMode());

        // Read operations allowed
        $manager->assertActionPermitted(isWriteOperation: false, isCustomerContext: true);

        // Write operations blocked
        $this->expectException(OperationModeRestrictionException::class);
        $this->expectExceptionMessage('Write operation [Data modification] is blocked: System is in READ_ONLY mode');

        $manager->assertActionPermitted(isWriteOperation: true, isCustomerContext: true);
    }

    public function testRecoveryModeBlocksCustomerAccessWhileAllowingSuperAdmin(): void
    {
        $manager = new OperationalModeManager($this->db, $this->tempLockFile);
        $manager->transitionTo(OperationalMode::RECOVERY, 'Emergency disaster restoration');

        // Super Admin access permitted
        $manager->assertActionPermitted(isWriteOperation: true, isCustomerContext: false, isSuperAdmin: true);

        // Customer access blocked
        $this->expectException(OperationModeRestrictionException::class);
        $this->expectExceptionMessage('Customer access is disabled while system is in RECOVERY mode');

        $manager->assertActionPermitted(isWriteOperation: false, isCustomerContext: true, isSuperAdmin: false);
    }

    public function testFullMaintenanceBlocksGeneralTrafficWhileAllowingWhitelistedIp(): void
    {
        $manager = new OperationalModeManager($this->db, $this->tempLockFile);
        $manager->transitionTo(
            OperationalMode::FULL_MAINTENANCE,
            'Scheduled core database migration window',
            whitelistIps: ['192.168.1.100', '10.0.0.50']
        );

        $this->assertSame(OperationalMode::FULL_MAINTENANCE, $manager->getCurrentMode());

        // Whitelisted IP allowed
        $manager->assertActionPermitted(isWriteOperation: false, isCustomerContext: true, clientIp: '192.168.1.100');

        // Non-whitelisted IP blocked
        $this->expectException(OperationModeRestrictionException::class);
        $this->expectExceptionMessage('System is currently unavailable: Scheduled core database migration window');

        $manager->assertActionPermitted(isWriteOperation: false, isCustomerContext: true, clientIp: '203.0.113.19');
    }

    public function testSafeModePermitsWritesWhileDisablingAutomatedCron(): void
    {
        $manager = new OperationalModeManager($this->db, $this->tempLockFile);
        $manager->transitionTo(OperationalMode::SAFE, 'Safe mode for plugin troubleshooting');

        $this->assertSame(OperationalMode::SAFE, $manager->getCurrentMode());
        $status = $manager->getModeStatus();

        $this->assertTrue($status['allows_writes']);
        $this->assertTrue($status['allows_customer_access']);
        $this->assertFalse($status['allows_cron']);
    }

    public function testTransitionToNormalClearsLockFile(): void
    {
        $manager = new OperationalModeManager($this->db, $this->tempLockFile);
        $manager->transitionTo(OperationalMode::FULL_MAINTENANCE, 'Maintenance');

        $this->assertFileExists($this->tempLockFile);
        $this->assertSame('full_maintenance', file_get_contents($this->tempLockFile));

        $manager->transitionTo(OperationalMode::NORMAL, 'Restoring normal operations');
        $this->assertFileDoesNotExist($this->tempLockFile);
        $this->assertSame(OperationalMode::NORMAL, $manager->getCurrentMode());
    }
}
