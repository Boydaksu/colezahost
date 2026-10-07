<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Domain\Module;

use Coleza\Domain\Identity\Audit\AuditLogger;
use Coleza\Domain\Module\ModuleLifecycleService;
use Coleza\Domain\Module\ModuleManifest;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class ModuleLifecycleServiceTest extends TestCase
{
    private Connection $connection;
    private AuditLogger $auditLogger;
    private ModuleLifecycleService $moduleService;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->connection = new Connection($pdo, 'sqlite');
        $this->auditLogger = new AuditLogger($this->connection);
        $this->moduleService = new ModuleLifecycleService($this->connection, coreVersion: '1.0.0', auditLogger: $this->auditLogger);
    }

    public function testManifestValidation(): void
    {
        $manifest = ModuleManifest::fromArray([
            'id' => 'cpanel-provisioner',
            'name' => 'cPanel & WHM Provisioner',
            'version' => '1.0.0',
            'type' => 'server_provisioner',
            'min_core_version' => '1.0.0',
            'capabilities' => ['cpanel.provision', 'server.metrics'],
        ]);

        $this->assertSame('cpanel-provisioner', $manifest->id);
        $this->assertSame('server_provisioner', $manifest->type);
        $this->assertContains('cpanel.provision', $manifest->capabilities);
    }

    public function testInvalidManifestThrowsValidationException(): void
    {
        $this->expectException(ValidationException::class);
        ModuleManifest::fromArray([
            'id' => 'INVALID ID WITH SPACES',
            'name' => 'Bad Module',
            'version' => '1.0.0',
            'type' => 'unsupported_type',
        ]);
    }

    public function testIncompatibleCoreVersionBlocksInstallation(): void
    {
        $futureManifest = ModuleManifest::fromArray([
            'id' => 'future-extension',
            'name' => 'Future Extension',
            'version' => '2.0.0',
            'type' => 'integration',
            'min_core_version' => '2.0.0', // current core is 1.0.0
        ]);

        $this->expectException(ValidationException::class);
        $this->moduleService->install($futureManifest);
    }

    public function testModuleLifecycleAndCapabilityLookup(): void
    {
        $manifest = ModuleManifest::fromArray([
            'id' => 'stripe-checkout',
            'name' => 'Stripe Checkout Gateway',
            'version' => '1.2.0',
            'type' => 'payment_gateway',
            'min_core_version' => '1.0.0',
            'capabilities' => ['payment.credit_card', 'payment.webhook'],
        ]);

        // Install
        $this->moduleService->install($manifest, actorUserId: 1);
        $this->assertFalse($this->moduleService->isEnabled('stripe-checkout'));

        // Query capability when disabled -> returns empty
        $this->assertEmpty($this->moduleService->getModulesWithCapability('payment.credit_card'));

        // Enable
        $this->moduleService->enable('stripe-checkout', actorUserId: 1);
        $this->assertTrue($this->moduleService->isEnabled('stripe-checkout'));

        // Query capability when enabled -> returns module ID
        $modules = $this->moduleService->getModulesWithCapability('payment.credit_card');
        $this->assertSame(['stripe-checkout'], $modules);

        // Disable
        $this->moduleService->disable('stripe-checkout', actorUserId: 1);
        $this->assertFalse($this->moduleService->isEnabled('stripe-checkout'));
        $this->assertEmpty($this->moduleService->getModulesWithCapability('payment.credit_card'));

        // Verify audit logs
        $logs = $this->auditLogger->getLogsForUser(1);
        $this->assertCount(3, $logs); // installed, enabled, disabled
        $this->assertSame('MODULE_DISABLED', $logs[0]['event_type']);
        $this->assertSame('MODULE_ENABLED', $logs[1]['event_type']);
        $this->assertSame('MODULE_INSTALLED', $logs[2]['event_type']);
    }
}
