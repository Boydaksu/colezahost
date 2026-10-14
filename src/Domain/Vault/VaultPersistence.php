<?php

declare(strict_types=1);
namespace Coleza\Domain\Vault;
use Coleza\Foundation\Database\Connection;

final class VaultPersistence
{
    public static function prepare(Connection $db): void
    {
        SecretStorageUpgrade::assertStorage($db, VaultService::schemaRequirements());
        $groups = [];
        if ($db->getDriverName() === 'mysql') {
            foreach ($db->select('SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SUB_PART FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX', ['vault_secrets']) as $row) {
                if ((int) $row['NON_UNIQUE'] === 0) { if ($row['SUB_PART'] !== null) { throw new \LogicException('Vault requires full unique keys.'); } $groups[$row['INDEX_NAME']][] = $row['COLUMN_NAME']; }
            }
            if (($groups['PRIMARY'] ?? []) !== ['id']) { throw new \LogicException('Vault requires canonical primary identity.'); }
        } else {
            $pk = array_values(array_filter($db->select('PRAGMA table_info(vault_secrets)'), static fn(array $r): bool => (int) $r['pk'] > 0));
            if (array_column($pk, 'name') !== ['id']) { throw new \LogicException('Vault requires canonical primary identity.'); }
            foreach ($db->select('PRAGMA index_list(vault_secrets)') as $index) {
                if ((int) $index['unique'] !== 1) { continue; }
                if ((int) $index['partial'] !== 0) { throw new \LogicException('Vault requires full unique keys.'); }
                $name = str_replace("'", "''", $index['name']); $groups[$name] = array_column($db->select("PRAGMA index_info('{$name}')"), 'name');
            }
        }
        if (!in_array(['namespace', 'secret_key'], $groups, true)) { throw new \LogicException('Vault requires unique namespace/key identity.'); }
        foreach ($groups as $columns) { if (!in_array($columns, [['id'], ['namespace', 'secret_key']], true)) { throw new \LogicException('Unexpected unique key can redirect Vault upsert.'); } }
    }
}
