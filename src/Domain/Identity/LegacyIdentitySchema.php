<?php
declare(strict_types=1);
namespace Coleza\Domain\Identity;

use Coleza\Foundation\Database\Connection;

/** Additive upgrade for known installer, Auth and WHMCS identity layouts. */
final class LegacyIdentitySchema
{
    public function __construct(private Connection $db, private string $prefix = '')
    {
        if ($prefix !== '' && !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $prefix)) {
            throw new \InvalidArgumentException('Invalid table prefix.');
        }
    }

    public function preflight(): void
    {
        $users = $this->columns('users');
        if ($users !== []) {
            $this->requireColumns($users, ['id', 'email', 'password_hash'], 'users');
            if (!in_array('status', $users, true) && !in_array('is_active', $users, true)) {
                throw new \RuntimeException('Legacy users access state is unknown; reconcile before upgrading.');
            }
        }
        $orgs = $this->columns('organizations');
        if ($orgs !== []) { $this->requireColumns($orgs, ['id', 'name', 'slug', 'owner_user_id'], 'organizations'); }
    }

    public function upgrade(): void
    {
        \Coleza\Foundation\Database\PdoSchema::autoIncrement($this->db->getPdo());
        $this->preflight();
        if ($this->db->getDriverName() === 'sqlite' && !$this->db->inTransaction()) {
            $this->db->transaction(fn () => $this->upgradeColumns());
        } else { $this->upgradeColumns(); }
    }

    private function upgradeColumns(): void
    {
        $users = $this->prefix . 'users';
        $this->addColumns('users', [
            'name' => 'VARCHAR(200) NULL', 'first_name' => 'VARCHAR(100) NULL', 'last_name' => 'VARCHAR(100) NULL',
            'status' => 'VARCHAR(50) NULL', 'is_active' => 'INT NULL',
            'email_verified_at' => 'TIMESTAMP NULL', 'created_at' => 'TIMESTAMP NULL', 'updated_at' => 'TIMESTAMP NULL',
        ]);
        // NULL added columns make a retry safe even if MySQL committed only part of the DDL.
        $this->db->statement("UPDATE {$users} SET status = CASE WHEN is_active = 1 THEN 'active' ELSE 'inactive' END WHERE status IS NULL");
        $this->db->statement("UPDATE {$users} SET is_active = CASE WHEN status = 'active' THEN 1 ELSE 0 END WHERE is_active IS NULL");
        // A known disabled/suspended state always takes precedence over an active flag.
        $this->db->statement("UPDATE {$users} SET is_active = 0 WHERE is_active = 1 AND status <> 'active'");
        $fullName = $this->db->getDriverName() === 'mysql'
            ? "TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')))"
            : "TRIM(COALESCE(first_name, '') || ' ' || COALESCE(last_name, ''))";
        $this->db->statement("UPDATE {$users} SET name = {$fullName} WHERE name IS NULL AND (first_name IS NOT NULL OR last_name IS NOT NULL)");
        $this->addColumns('organizations', ['status' => "VARCHAR(50) NOT NULL DEFAULT 'active'", 'billing_currency' => "VARCHAR(3) NOT NULL DEFAULT 'USD'", 'created_at' => 'TIMESTAMP NULL']);
    }

    /** @param array<string,string> $definitions */
    private function addColumns(string $table, array $definitions): void
    {
        $columns = $this->columns($table);
        foreach ($definitions as $column => $definition) {
            if (!in_array($column, $columns, true)) { $this->db->statement("ALTER TABLE {$this->prefix}{$table} ADD COLUMN {$column} {$definition}"); }
        }
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        $name = $this->prefix . $table;
        if ($this->db->getDriverName() === 'sqlite') {
            return array_column($this->db->select("PRAGMA table_info({$name})"), 'name');
        }
        $exists = $this->db->selectOne('SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$name]);
        return $exists === null ? [] : array_column($this->db->select("SHOW COLUMNS FROM {$name}"), 'Field');
    }

    private function requireColumns(array $columns, array $required, string $table): void
    {
        foreach ($required as $column) {
            if (!in_array($column, $columns, true)) { throw new \RuntimeException("Unsupported legacy {$table} schema: missing {$column}; reconcile before upgrading."); }
        }
    }
}
