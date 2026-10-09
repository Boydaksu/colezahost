<?php

declare(strict_types=1);

namespace Coleza\Domain\Identity\Rbac;

use Coleza\Foundation\Database\Connection;
use RuntimeException;

/** Shared schema for fresh installation and RBAC; preserves legacy installer metadata. */
final class RbacSchema
{
    public function __construct(private Connection $db, private string $prefix = '')
    {
        if ($prefix !== '' && !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $prefix)) {
            throw new \InvalidArgumentException('Invalid table prefix.');
        }
    }

    public function ensure(): void
    {
        $id = $this->db->getDriverName() === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
        $this->db->statement(sprintf('CREATE TABLE IF NOT EXISTS %sroles (
            id %s, name VARCHAR(50) NOT NULL UNIQUE, scope VARCHAR(20) NOT NULL DEFAULT "system",
            description VARCHAR(255) NULL, display_name VARCHAR(100) NULL,
            permissions_json TEXT NULL, is_system INT NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)', $this->prefix, $id));
        $this->db->statement(sprintf('CREATE TABLE IF NOT EXISTS %spermissions (
            id %s, name VARCHAR(100) NOT NULL UNIQUE, description VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)', $this->prefix, $id));
        $this->db->statement(sprintf('CREATE TABLE IF NOT EXISTS %srole_permissions (
            role_id INT NOT NULL, permission_name VARCHAR(100) NOT NULL,
            PRIMARY KEY (role_id, permission_name))', $this->prefix));
        $this->createUserRoles($this->prefix . 'user_roles');
    }

    private function createUserRoles(string $table): void
    {
        $this->db->statement(sprintf('CREATE TABLE IF NOT EXISTS %s (
            user_id INT NOT NULL, role_id INT NOT NULL, organization_id INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, role_id, organization_id))', $table));
    }

    /** Idempotent upgrade of both legacy installer and legacy RBAC schemas. */
    public function upgrade(): void
    {
        if ($this->db->getDriverName() === 'sqlite' && !$this->db->inTransaction()) {
            $this->db->transaction(fn () => $this->upgradeSchema());
        } else {
            if ($this->db->getDriverName() === 'mysql' && $this->db->inTransaction()) {
                throw new RuntimeException('Identity schema upgrades cannot run inside an application transaction.');
            }
            $this->upgradeSchema();
        }
    }

    private function upgradeSchema(): void
    {
        $this->ensure();
        $roles = $this->prefix . 'roles';
        $columns = $this->columns($roles);
        foreach (['scope' => 'VARCHAR(20) NOT NULL DEFAULT "system"', 'description' => 'VARCHAR(255) NULL',
            'display_name' => 'VARCHAR(100) NULL', 'permissions_json' => 'TEXT NULL', 'is_system' => 'INT NOT NULL DEFAULT 1'] as $name => $type) {
            if (!isset($columns[$name])) {
                $this->db->statement("ALTER TABLE {$roles} ADD COLUMN {$name} {$type}");
            }
        }

        // Validate permission data before replacing any mapping table.
        $permissions = [];
        foreach ($this->db->select("SELECT id, permissions_json FROM {$roles} WHERE permissions_json IS NOT NULL") as $role) {
            $values = json_decode((string) $role['permissions_json'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($values)) {
                throw new RuntimeException('Legacy role permissions must be an array.');
            }
            foreach ($values as $value) {
                if (!is_string($value) || $value === '' || strlen($value) > 100) {
                    throw new RuntimeException('Invalid legacy role permission.');
                }
                $permissions[] = [(int) $role['id'], $value];
            }
        }

        $mapping = $this->prefix . 'user_roles';
        $columns = $this->columns($mapping);
        $org = $columns['organization_id'] ?? null;
        if ($org === null || !$org['not_null'] || (string) $org['default'] !== '0') {
            $replacement = $mapping . '_d02_new';
            $backup = $mapping . '_d02_legacy';
            $this->createUserRoles($replacement);
            $organization = $org === null ? '0' : 'COALESCE(organization_id, 0)';
            if ($org !== null && $this->db->selectOne("SELECT user_id FROM {$mapping} WHERE organization_id < 0 LIMIT 1") !== null) {
                throw new RuntimeException('Legacy role assignments contain an invalid organization ID.');
            }
            // A previous attempt may have stopped after copying but before the atomic rename.
            // The original mapping is still authoritative until the rename completes.
            $this->db->statement("DELETE FROM {$replacement}");
            $createdAt = isset($columns['created_at']) ? 'MIN(COALESCE(created_at, CURRENT_TIMESTAMP))' : 'CURRENT_TIMESTAMP';
            $grouping = $org === null ? 'user_id, role_id' : 'user_id, role_id, COALESCE(organization_id, 0)';
            $this->db->statement("INSERT INTO {$replacement} (user_id, role_id, organization_id, created_at)
                SELECT user_id, role_id, {$organization}, {$createdAt} FROM {$mapping} GROUP BY {$grouping}");
            if ($this->db->getDriverName() === 'mysql') {
                $this->db->statement("RENAME TABLE {$mapping} TO {$backup}, {$replacement} TO {$mapping}");
                $this->db->statement("DROP TABLE {$backup}");
            } else {
                $this->db->statement("DROP TABLE {$mapping}");
                $this->db->statement("ALTER TABLE {$replacement} RENAME TO {$mapping}");
            }
        }
        foreach ($permissions as [$roleId, $permission]) {
            $table = $this->prefix . 'role_permissions';
            if ($this->db->selectOne("SELECT role_id FROM {$table} WHERE role_id = ? AND permission_name = ?", [$roleId, $permission]) === null) {
                $this->db->insert($table, ['role_id' => $roleId, 'permission_name' => $permission]);
            }
        }
    }

    /** @return array<string, array{not_null: bool, default: mixed}> */
    private function columns(string $table): array
    {
        $result = [];
        if ($this->db->getDriverName() === 'sqlite') {
            foreach ($this->db->select("PRAGMA table_info({$table})") as $column) {
                $result[$column['name']] = ['not_null' => (bool) $column['notnull'], 'default' => $column['dflt_value']];
            }
        } else {
            foreach ($this->db->select("SHOW COLUMNS FROM {$table}") as $column) {
                $result[$column['Field']] = ['not_null' => $column['Null'] === 'NO', 'default' => $column['Default']];
            }
        }
        return $result;
    }
}
