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

    public function __construct(private Connection $db)
    {
    }

    public function ensureTables(): void
    {
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        // Roles table
        $sqlRoles = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                name VARCHAR(50) NOT NULL UNIQUE,
                scope VARCHAR(20) NOT NULL DEFAULT "system",
                description VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->rolesTable,
            $autoInc
        );
        $this->db->statement($sqlRoles);

        // Permissions table
        $sqlPermissions = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                name VARCHAR(100) NOT NULL UNIQUE,
                description VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->permissionsTable,
            $autoInc
        );
        $this->db->statement($sqlPermissions);

        // Role-Permissions mapping
        $sqlRolePerms = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                role_id INT NOT NULL,
                permission_name VARCHAR(100) NOT NULL,
                PRIMARY KEY (role_id, permission_name)
            )',
            $this->rolePermissionsTable
        );
        $this->db->statement($sqlRolePerms);

        // User-Roles mapping (with optional organization_id for scoped org roles)
        $sqlUserRoles = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                user_id INT NOT NULL,
                role_id INT NOT NULL,
                organization_id INT NULL,
                PRIMARY KEY (user_id, role_id, organization_id)
            )',
            $this->userRolesTable
        );
        $this->db->statement($sqlUserRoles);
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
        $this->ensureTables();
        $roleId = $this->findOrCreateRole($roleName, $orgId !== null ? 'organization' : 'system');

        $existing = $this->db->selectOne(
            sprintf(
                'SELECT user_id FROM %s WHERE user_id = :uid AND role_id = :rid AND %s',
                $this->userRolesTable,
                $orgId === null ? 'organization_id IS NULL' : 'organization_id = :oid'
            ),
            $orgId === null ? ['uid' => $userId, 'rid' => $roleId] : ['uid' => $userId, 'rid' => $roleId, 'oid' => $orgId]
        );

        if ($existing === null) {
            $this->db->insert($this->userRolesTable, [
                'user_id' => $userId,
                'role_id' => $roleId,
                'organization_id' => $orgId,
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
            $orgId === null ? 'ur.organization_id IS NULL' : '(ur.organization_id = :oid OR ur.organization_id IS NULL)'
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
