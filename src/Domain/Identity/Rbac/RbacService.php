<?php

declare(strict_types=1);

namespace Coleza\Domain\Identity\Rbac;

use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;

final class RbacService
{
    private string $rolesTable = 'roles';
    private string $permissionsTable = 'permissions';
    private string $rolePermissionsTable = 'role_permissions';
    private string $userRolesTable = 'user_roles';

    // Built-in standard permissions matrix
    private const DEFAULT_ROLES = [
        'superadmin' => [
            'scope' => 'system',
            'permissions' => ['*'],
        ],
        'billing_admin' => [
            'scope' => 'system',
            'permissions' => ['invoices.view', 'invoices.manage', 'payments.manage', 'gateways.view'],
        ],
        'support_agent' => [
            'scope' => 'system',
            'permissions' => ['tickets.view', 'tickets.reply', 'users.view', 'services.view'],
        ],
        'org_owner' => [
            'scope' => 'organization',
            'permissions' => ['org.*'],
        ],
        'org_admin' => [
            'scope' => 'organization',
            'permissions' => ['org.members.view', 'org.members.invite', 'org.services.manage', 'org.billing.view'],
        ],
        'org_member' => [
            'scope' => 'organization',
            'permissions' => ['org.services.view', 'org.tickets.create'],
        ],
    ];

    public function __construct(private Connection $db, string $prefix = '')
    {
        new RbacSchema($db, $prefix); // Validate the prefix before interpolating identifiers.
        $this->rolesTable = $prefix . 'roles';
        $this->permissionsTable = $prefix . 'permissions';
        $this->rolePermissionsTable = $prefix . 'role_permissions';
        $this->userRolesTable = $prefix . 'user_roles';
    }

    public function ensureTables(): void
    {
        $prefix = substr($this->rolesTable, 0, -strlen('roles'));
        (new RbacSchema($this->db, $prefix))->ensure();
    }

    /**
     * Seed or get role ID.
     */
    public function findOrCreateRole(string $name, string $scope = 'system', ?string $description = null): int
    {
        $this->ensureTables();
        $row = $this->db->selectOne(
            sprintf('SELECT id FROM %s WHERE name = :name', $this->rolesTable),
            ['name' => $name]
        );

        if ($row !== null) {
            return (int) $row['id'];
        }

        return (int) $this->db->insert($this->rolesTable, [
            'name' => $name,
            'scope' => $scope,
            'description' => $description,
            'display_name' => $description ?? $name,
        ]);
    }

    /**
     * Grant a permission string to a role.
     */
    public function grantPermission(int $roleId, string $permissionName): void
    {
        $this->ensureTables();
        $existing = $this->db->selectOne(
            sprintf('SELECT role_id FROM %s WHERE role_id = :rid AND permission_name = :p', $this->rolePermissionsTable),
            ['rid' => $roleId, 'p' => $permissionName]
        );

        if ($existing === null) {
            $this->db->insert($this->rolePermissionsTable, [
                'role_id' => $roleId,
                'permission_name' => $permissionName,
            ]);
        }
    }

    /**
     * Assign a role to a user (globally or within an organization).
     */
    public function assignRole(int $userId, string $roleName, ?int $orgId = null): void
    {
        if ($orgId !== null && $orgId <= 0) {
            throw new ValidationException(['organization_id' => 'Organization ID must be positive.'], 'Invalid organization scope');
        }
        $this->ensureTables();
        $roleId = $this->findOrCreateRole($roleName, $orgId !== null ? 'organization' : 'system');

        $existing = $this->db->selectOne(
            sprintf(
                'SELECT user_id FROM %s WHERE user_id = :uid AND role_id = :rid AND %s',
                $this->userRolesTable,
                $orgId === null ? 'organization_id = 0' : 'organization_id = :oid'
            ),
            $orgId === null ? ['uid' => $userId, 'rid' => $roleId] : ['uid' => $userId, 'rid' => $roleId, 'oid' => $orgId]
        );

        if ($existing === null) {
            $this->db->insert($this->userRolesTable, [
                'user_id' => $userId,
                'role_id' => $roleId,
                'organization_id' => $orgId ?? 0,
            ]);
        }
    }

    /**
     * Check if user has permission.
     * Default deny principle enforced: returns true only on explicit match or wildcard.
     */
    public function hasPermission(int $userId, string $permission, ?int $orgId = null): bool
    {
        $this->ensureTables();

        $sql = sprintf(
            'SELECT rp.permission_name 
             FROM %s ur
             JOIN %s rp ON rp.role_id = ur.role_id
             WHERE ur.user_id = :uid 
               AND (%s)',
            $this->userRolesTable,
            $this->rolePermissionsTable,
            $orgId === null ? 'ur.organization_id = 0' : '(ur.organization_id = :oid OR ur.organization_id = 0)'
        );

        $params = ['uid' => $userId];
        if ($orgId !== null) {
            $params['oid'] = $orgId;
        }

        $rows = $this->db->select($sql, $params);

        foreach ($rows as $row) {
            $perm = (string) $row['permission_name'];

            // Universal superadmin wildcard
            if ($perm === '*') {
                return true;
            }

            // Exact match
            if ($perm === $permission) {
                return true;
            }

            // Namespace wildcard (e.g. org.* matches org.billing.view)
            if (str_ends_with($perm, '.*')) {
                $prefix = substr($perm, 0, -2);
                if (str_starts_with($permission, $prefix . '.')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Authorize action or throw ValidationException (Default-Deny).
     */
    public function authorize(int $userId, string $permission, ?int $orgId = null): void
    {
        if (!$this->hasPermission($userId, $permission, $orgId)) {
            throw new ValidationException(
                ['authorization' => [sprintf('User does not have required [%s] permission.', $permission)]],
                'Access Denied: Missing required permission.'
            );
        }
    }
}
