<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
use Coleza\Foundation\Database\Connection;
use Coleza\Domain\Automation\Persistence\{AutomationCodec, AutomationSecretStore};
/** Test-only edits to decrypted evidence; runtime has no corresponding plaintext fallback. */
final class AutomationStorageFixture
{
    public static function install(Connection $db): void
    {
        \Coleza\Domain\Vault\VaultReferenceStore::ensureSchema($db);
        \Coleza\Domain\Automation\Persistence\AutomationSchema::installSchema($db);
    }
    public static function decode(Connection $db, string $stored): array
    {
        if (!str_starts_with($stored, 'vault:')) { return AutomationCodec::decode($stored); }
        $key = substr($stored, strlen('vault:v1:'));
        $row = $db->selectOne('SELECT * FROM vault_secrets WHERE namespace = ? AND secret_key = ?', [AutomationSecretStore::NAMESPACE, $key]);
        if ($row === null) { throw new \RuntimeException('Fixture secret missing.'); }
        return AutomationCodec::decode((new \Coleza\Domain\Vault\SecretCipher(SecretFixtureKey::encryptor()))->open($row['encrypted_value'], \Coleza\Domain\Vault\VaultService::scope($row)));
    }
    public static function insert(Connection $db, string $table, array $row): int
    {
        return $db->transaction(function () use ($db, $table, $row): int {
            $row['payload'] = (new AutomationSecretStore($db, SecretFixtureKey::encryptor()))->write($table, $row, self::decode($db, $row['payload']));
            return $db->insert($table, $row);
        });
    }
    public static function update(Connection $db, string $table, array $data, string $where, array $params): int
    {
        if (!isset($data['payload'])) { return $db->update($table, $data, $where, $params); }
        return $db->transaction(function () use ($db, $table, $data, $where, $params): int {
            $count = 0;
            foreach ($db->select("SELECT * FROM {$table} WHERE {$where}" . $db->forUpdate(), $params) as $row) {
                $owner = array_replace($row, $data);
                $fields = $data; $fields['payload'] = (new AutomationSecretStore($db, SecretFixtureKey::encryptor()))->write($table, $owner, self::decode($db, $data['payload']));
                $clause = $table === 'automation_rule_versions' ? 'rule_id = :owner AND version_number = :version' : 'id = :owner';
                $bindings = $table === 'automation_rule_versions' ? ['owner' => $row['rule_id'], 'version' => $row['version_number']] : ['owner' => $row['id']];
                $count += $db->update($table, $fields, $clause, $bindings);
            }
            return $count;
        });
    }
}
