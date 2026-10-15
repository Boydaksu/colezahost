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

    /** Runtime preflight; never execute implicit-commit DDL in an application transaction. */
    public function prepare(): void
    {
        $requirements = [$this->rolesTable => ['id', 'name', 'scope'], $this->rolePermissionsTable => ['role_id', 'permission_name'], $this->userRolesTable => ['user_id', 'role_id', 'organization_id']];
        if (!\Coleza\Foundation\Database\PdoSchema::useInstalledSchema($this->db, $requirements)) { $this->ensureTables(); }
        if ($this->db->getDriverName() === 'mysql') {
            foreach (array_keys($requirements) as $table) {
                $row = $this->db->selectOne('SELECT ENGINE FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]);
                if ($row === null || strcasecmp((string) $row['ENGINE'], 'InnoDB') !== 0) { throw new \LogicException('Transactional RBAC storage required.'); }
            }
        }
    }
    public function usesConnection(Connection $db): bool { return $this->db === $db; }
    public function isSystemActor(int $userId): bool
    {
        $this->prepare();
        return $this->db->selectOne("SELECT ur.user_id FROM {$this->userRolesTable} ur JOIN {$this->rolesTable} r ON r.id = ur.role_id WHERE ur.user_id = ? AND ur.organization_id = 0 AND r.scope = 'system'" . $this->db->forUpdate(), [$userId]) !== null;
    }

    /**
     * Seed or get role ID.
     */
    public function findOrCreateRole(string $name, string $scope = 'system', ?string $description = null): int
    {
        $this->prepare();
        $row = $this->db->selectOne(
            sprintf('SELECT id, scope FROM %s WHERE name = :name', $this->rolesTable),
            ['name' => $name]
        );

        if ($row !== null) {
            if ($row['scope'] !== $scope) { throw new ValidationException(['role' => ['Role scope does not match assignment.']]); }
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
        $this->prepare();
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
        $this->prepare();
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
        $this->prepare();
        return $this->hasPermissionFromInitializedSchema($userId, $permission, $orgId);
    }

    /** Read migrated permission tables without DDL, including inside application transactions. */
    public function hasPermissionFromInitializedSchema(int $userId, string $permission, ?int $orgId = null): bool
    {

        $sql = sprintf(
            'SELECT rp.permission_name, ur.organization_id, r.scope
             FROM %s ur
             JOIN %s rp ON rp.role_id = ur.role_id
             JOIN %s r ON r.id = ur.role_id
             WHERE ur.user_id = :uid
               AND (%s)',
            $this->userRolesTable,
            $this->rolePermissionsTable,
            $this->rolesTable,
            $orgId === null ? 'ur.organization_id = 0' : '(ur.organization_id = :oid OR ur.organization_id = 0)'
        );

        $params = ['uid' => $userId];
        if ($orgId !== null) {
            $params['oid'] = $orgId;
        }

        $rows = $this->db->select($sql . $this->db->forUpdate(), $params);

        foreach ($rows as $row) {
            $organizationRole = $row['scope'] === 'organization' && $orgId !== null && $orgId > 0 && (int)$row['organization_id'] === $orgId;
            $systemRole = $row['scope'] === 'system' && (int)$row['organization_id'] === 0;
            if (!$systemRole && !$organizationRole) { continue; }
            if ($organizationRole && (!str_starts_with($permission, 'org.') || !$this->currentMembership($userId, $orgId))) { continue; }
            $perm = (string) $row['permission_name'];
            if ($organizationRole && !str_starts_with($perm, 'org.')) { continue; }
            if ($systemRole && str_starts_with($permission, 'org.') && $perm !== '*') { continue; }


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
    private function currentMembership(int $userId, int $orgId): bool
    {
        $prefix=substr($this->rolesTable,0,-strlen('roles'));
        $table=$prefix.'organization_members';$orgs=$prefix.'organizations';
        if (!\Coleza\Domain\Vault\KeyBinding::tableExists($this->db,$table) || !\Coleza\Domain\Vault\KeyBinding::tableExists($this->db,$orgs)) { return false; }
        $row=$this->db->selectOne("SELECT o.*,m.role FROM {$orgs} o JOIN {$table} m ON m.organization_id=o.id WHERE o.id=? AND m.user_id=?".$this->db->forUpdate(),[$orgId,$userId]);
        if ($row===null || (array_key_exists('status',$row) && $row['status']!=='active')) { return false; }
        return $row['role']==='owner' ? (int)$row['owner_user_id']===$userId : (int)$row['owner_user_id']!==$userId && in_array($row['role'],['admin','member','billing','technical'],true);
    }
}
