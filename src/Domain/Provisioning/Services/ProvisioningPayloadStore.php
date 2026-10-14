<?php
declare(strict_types=1);
namespace Coleza\Domain\Provisioning\Services;

use Coleza\Domain\Vault\{Encryptor, VaultReferenceStore, SecretStorageUpgrade, KeyBinding};
use Coleza\Foundation\Database\Connection;

/** Execution data stays intact; only its owner reference is persisted in the operation. */
final class ProvisioningPayloadStore
{
    public const NAMESPACE = 'provisioning';
    public function __construct(private Connection $db, private ?Encryptor $encryptor = null) {}
    public static function key(array $owner, string $column): string
    {
        if (!in_array($column, ['payload_json', 'result_json'], true)) { throw new \InvalidArgumentException('Invalid operation data column.'); }
        return hash('sha256', json_encode([$owner['operation_uuid'], (int) $owner['service_id'],
            $owner['server_id'] === null ? null : (int) $owner['server_id'], $owner['provider_slug'],
            $owner['action'], $owner['correlation_id'], $column], JSON_THROW_ON_ERROR));
    }
    public static function decode(string $json): array
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) { throw new \RuntimeException('Operation data must be a JSON array or object.'); }
        return $data;
    }
    public function write(array $owner, string $column, #[\SensitiveParameter] array $data): string
    {
        return (new VaultReferenceStore($this->db, $this->encryptor))->write(self::NAMESPACE, self::key($owner, $column),
            json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
    public function read(array $owner, string $column): array
    {
        $stored = $owner[$column];
        if ($stored === null || $stored === '') { return []; }
        return self::decode((new VaultReferenceStore($this->db, $this->encryptor))->read(self::NAMESPACE, self::key($owner, $column), (string) $stored));
    }
    /** No runtime plaintext fallback. Caller stops writers and confirms the provenance of legacy JSON. */
    public function upgrade(bool $legacyPlaintextConfirmed = false): int
    {
        if (!$legacyPlaintextConfirmed || $this->db->inTransaction()) { throw new \LogicException('Confirmed maintenance with stopped writers and an owned transaction is required.'); }
        SecretStorageUpgrade::assertStorage($this->db, ['provisioning_operations' => ['id', 'operation_uuid', 'service_id', 'server_id', 'provider_slug', 'action', 'correlation_id', 'payload_json', 'result_json']]
            + \Coleza\Domain\Vault\VaultService::schemaRequirements() + ['security_audit_logs' => ['id', 'actor_user_id', 'event_type', 'target_resource', 'payload']]);
        return $this->db->transaction(function (): int {
            KeyBinding::guard($this->db, $this->encryptor); $changes = [];
            foreach ($this->db->select('SELECT * FROM provisioning_operations ORDER BY id' . $this->db->forUpdate()) as $row) {
                foreach (['payload_json', 'result_json'] as $column) {
                    $value = $row[$column]; if ($value === null || $value === '') { continue; }
                    if (str_starts_with($value, 'vault:')) { $this->read($row, $column); continue; }
                    $changes[] = [$row, $column, self::decode($value)];
                }
            }
            foreach ($changes as [$row, $column, $data]) {
                $key = self::key($row, $column);
                if ($this->db->selectOne('SELECT id FROM vault_secrets WHERE namespace = ? AND secret_key = ?', [self::NAMESPACE, $key]) !== null) {
                    throw new \LogicException('Legacy operation has a conflicting Vault owner; reconcile explicitly.');
                }
                $this->db->update('provisioning_operations', [$column => $this->write($row, $column, $data)], 'id = :id', ['id' => $row['id']]);
            }
            return count($changes);
        });
    }
}
