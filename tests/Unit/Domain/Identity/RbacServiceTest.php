<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Domain\Identity;

use Coleza\Domain\Identity\Rbac\RbacService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class RbacServiceTest extends TestCase
{
    private Connection $connection;
    private RbacService $rbac;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->connection = new Connection($pdo, 'sqlite');
        $this->rbac = new RbacService($this->connection);
    }

    public function testDefaultDenyPrinciple(): void
    {
        $userId = 1;
        // User with no roles has no permissions
        $this->assertFalse($this->rbac->hasPermission($userId, 'invoices.view'));

        $this->expectException(ValidationException::class);
        $this->rbac->authorize($userId, 'invoices.view');
    }

    public function testSuperadminWildcardPermission(): void
    {
        $superadminId = 2;
        $roleId = $this->rbac->findOrCreateRole('superadmin', 'system');
        $this->rbac->grantPermission($roleId, '*');
        $this->rbac->assignRole($superadminId, 'superadmin');

        $this->assertTrue($this->rbac->hasPermission($superadminId, 'any.action.imaginable'));
        $this->assertTrue($this->rbac->hasPermission($superadminId, 'org.delete', orgId: 10));

        // Authorize does not throw
        $this->rbac->authorize($superadminId, 'server.reboot');
    }

    public function testGranularPermissions(): void
    {
        $agentId = 3;
        $roleId = $this->rbac->findOrCreateRole('support_agent', 'system');
        $this->rbac->grantPermission($roleId, 'tickets.view');
        $this->rbac->grantPermission($roleId, 'tickets.reply');
        $this->rbac->assignRole($agentId, 'support_agent');

        $this->assertTrue($this->rbac->hasPermission($agentId, 'tickets.view'));
        $this->assertTrue($this->rbac->hasPermission($agentId, 'tickets.reply'));
        $this->assertFalse($this->rbac->hasPermission($agentId, 'tickets.delete'));
        $this->assertFalse($this->rbac->hasPermission($agentId, 'invoices.view'));
    }

    public function testOrganizationScopedRoleAndNamespaceWildcard(): void
    {
        $orgMemberId = 4;
        $orgId = 42;

        $roleId = $this->rbac->findOrCreateRole('org_manager', 'organization');
        $this->rbac->grantPermission($roleId, 'org.services.*');
        $this->rbac->assignRole($orgMemberId, 'org_manager', orgId: $orgId);

        // Within organization 42, user has access to wildcard children
        $this->assertTrue($this->rbac->hasPermission($orgMemberId, 'org.services.create', orgId: $orgId));
        $this->assertTrue($this->rbac->hasPermission($orgMemberId, 'org.services.restart', orgId: $orgId));

        // Across different organization 99, user does not have access
        $this->assertFalse($this->rbac->hasPermission($orgMemberId, 'org.services.create', orgId: 99));

        // In global system scope, user does not have access
        $this->assertFalse($this->rbac->hasPermission($orgMemberId, 'org.services.create'));
    }
}
