<?php
declare(strict_types=1);
namespace Coleza\Domain\Installer;

use Coleza\Foundation\Database\Connection;

final class ProviderIdentitySchema
{
    public function __construct(private Connection $db, private string $prefix = '')
    {
        if ($prefix !== '' && !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $prefix)) { throw new \InvalidArgumentException('Invalid table prefix.'); }
    }
    public function ensure(): void
    {
        \Coleza\Foundation\Database\PdoSchema::autoIncrement($this->db->getPdo());
        $table = $this->prefix . 'installer_provider_identity';
        $this->db->statement("CREATE TABLE IF NOT EXISTS {$table} (
            id INT PRIMARY KEY CHECK (id = 1), organization_id INT NULL, brand_id INT NULL, owner_user_id INT NULL,
            CHECK ((organization_id IS NULL AND brand_id IS NULL AND owner_user_id IS NULL)
                OR (organization_id IS NOT NULL AND brand_id IS NOT NULL AND owner_user_id IS NOT NULL))
        )");
        $conflict = $this->db->getDriverName() === 'mysql' ? ' ON DUPLICATE KEY UPDATE id = id' : ' ON CONFLICT(id) DO NOTHING';
        $this->db->statement("INSERT INTO {$table} (id) VALUES (1)" . $conflict);
    }
}
