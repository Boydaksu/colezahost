<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Domain\Identity;

use Coleza\Domain\Identity\Audit\AuditLogger;
use Coleza\Domain\Identity\Impersonation\ImpersonationService;
use Coleza\Domain\Identity\Rbac\RbacService;
use Coleza\Domain\Identity\Session\DatabaseSessionHandler;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class ImpersonationAndAuditTest extends TestCase
{
    private Connection $connection;
    private DatabaseSessionHandler $sessionHandler;
    private RbacService $rbac;
    private AuditLogger $audit;
    private ImpersonationService $impersonation;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->connection = new Connection($pdo, 'sqlite');
        $this->sessionHandler = new DatabaseSessionHandler($this->connection);
        $this->rbac = new RbacService($this->connection);
        $this->audit = new AuditLogger($this->connection);
        $this->impersonation = new ImpersonationService($this->sessionHandler, $this->rbac, $this->audit);
    }

    public function testStartAndStopImpersonation(): void
    {
        $adminId = 1;
        $targetUserId = 50;
        $sessionId = 'sess_admin_imp';

        // Grant impersonation permission
        $roleId = $this->rbac->findOrCreateRole('superadmin');
        $this->rbac->grantPermission($roleId, 'users.impersonate');
        $this->rbac->assignRole($adminId, 'superadmin');

        // Initial admin session
        $this->sessionHandler->write($sessionId, ['logged' => true], userId: $adminId);

        // Start impersonation
        $this->impersonation->startImpersonation(
            $sessionId,
            $adminId,
            $targetUserId,
            reason: 'Investigating billing discrepancy',
            ip: '10.0.0.1'
        );

        $sessionData = $this->sessionHandler->read($sessionId);
        $this->assertSame($adminId, $sessionData['original_user_id']);
        $this->assertSame($adminId, $sessionData['impersonator_id']);
        $this->assertSame('Investigating billing discrepancy', $sessionData['impersonation_reason']);

        // Check audit log recorded
        $logs = $this->audit->getLogsForUser($targetUserId);
        $this->assertNotEmpty($logs);
        $this->assertSame('IMPERSONATION_STARTED', $logs[0]['event_type']);
        $this->assertSame($adminId, (int) $logs[0]['impersonator_user_id']);

        // Stop impersonation
        $this->impersonation->stopImpersonation($sessionId);

        $sessionAfter = $this->sessionHandler->read($sessionId);
        $this->assertArrayNotHasKey('impersonator_id', $sessionAfter);
        $this->assertArrayNotHasKey('original_user_id', $sessionAfter);
    }

    public function testUnauthorizedAdminCannotImpersonate(): void
    {
        $adminId = 2; // No role
        $targetId = 60;
        $sessionId = 'sess_unauth';
        $this->sessionHandler->write($sessionId, ['logged' => true], userId: $adminId);

        $this->expectException(ValidationException::class);
        $this->impersonation->startImpersonation($sessionId, $adminId, $targetId);
    }

    public function testCannotImpersonateSelf(): void
    {
        $adminId = 3;
        $roleId = $this->rbac->findOrCreateRole('superadmin');
        $this->rbac->grantPermission($roleId, 'users.impersonate');
        $this->rbac->assignRole($adminId, 'superadmin');

        $sessionId = 'sess_self';
        $this->sessionHandler->write($sessionId, ['logged' => true], userId: $adminId);

        $this->expectException(ValidationException::class);
        $this->impersonation->startImpersonation($sessionId, $adminId, $adminId);
    }

    public function testBlocksHighRiskOperationsDuringImpersonation(): void
    {
        $adminId = 4;
        $targetId = 70;
        $sessionId = 'sess_high_risk';

        $roleId = $this->rbac->findOrCreateRole('superadmin');
        $this->rbac->grantPermission($roleId, 'users.impersonate');
        $this->rbac->assignRole($adminId, 'superadmin');

        $this->sessionHandler->write($sessionId, ['logged' => true], userId: $adminId);
        $this->impersonation->startImpersonation($sessionId, $adminId, $targetId);

        // Safe operations pass
        $this->impersonation->guardOperation($sessionId, 'invoices.view');

        // High-risk operations (Security Constitution) are blocked
        $this->expectException(ValidationException::class);
        $this->impersonation->guardOperation($sessionId, 'password.change');
    }
}
