<?php
declare(strict_types=1);
namespace Coleza\Domain\Migration\Adoption;

use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Database\PdoSchema;

final class AdoptionSchema
{
    public static function ensure(Connection $db): void
    {
        if (PdoSchema::useInstalledSchemaInTransaction($db, ['migration_adoption_lock' => ['id']])) { return; }
        PdoSchema::autoIncrement($db->getPdo());
        $db->statement('CREATE TABLE IF NOT EXISTS migration_adoption_lock (id INT PRIMARY KEY, CHECK (id = 1))');
        $sql = $db->getDriverName() === 'mysql'
            ? 'INSERT IGNORE INTO migration_adoption_lock (id) VALUES (1)'
            : 'INSERT INTO migration_adoption_lock (id) VALUES (1) ON CONFLICT(id) DO NOTHING';
        $db->statement($sql);
    }
}
