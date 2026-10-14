<?php
declare(strict_types=1);
namespace Coleza\Domain\Domains;
use Coleza\Domain\Vault\{ApplicationKey, Encryptor, KeyBinding, SecretStorageUpgrade, VaultReferenceStore, VaultService};
use Coleza\Foundation\Database\Connection;

/** Only owner references live in domain records; execution data stays intact in Vault. */
final class DomainSecretStore
{
    public const NAMESPACE = 'domain';
    public function __construct(private Connection $db, private ?Encryptor $encryptor = null) {}
    public static function requirements(): array
    {
        return ['domains' => ['id', 'epp_code'], 'domain_registrar_operations' => ['id', 'domain_id', 'operation_type', 'idempotency_key', 'payload_json', 'result_json']];
    }
    public static function key(string $table, array $owner, string $column): string
    {
        $allowed = $table === 'domains' ? ['epp_code'] : ($table === 'domain_registrar_operations' ? ['payload_json', 'result_json'] : []);
        if (!in_array($column, $allowed, true)) { throw new \InvalidArgumentException('Unsupported domain secret owner.'); }
        $identity = $table === 'domains' ? [(int) $owner['id']] : [(int) $owner['id'], (int) $owner['domain_id'], $owner['operation_type'], $owner['idempotency_key']];
        return hash('sha256', json_encode([$table, $identity, $column], JSON_THROW_ON_ERROR));
    }
    private function encryptor(): Encryptor { return $this->encryptor ??= ApplicationKey::encryptor(ApplicationKey::configured()); }
    public function guard(): void { KeyBinding::guard($this->db, $this->encryptor()); }
    public function write(string $table, array $owner, string $column, #[\SensitiveParameter] ?string $plain): ?string
    {
        if (!$this->db->inTransaction()) { throw new \LogicException('Domain owner and secret must share a transaction.'); }
        SecretStorageUpgrade::assertStorage($this->db, [$table => self::requirements()[$table] ?? []]);
        $store = new VaultReferenceStore($this->db, $this->encryptor()); $key = self::key($table, $owner, $column);
        if ($plain === null) { $store->delete(self::NAMESPACE, $key); return null; }
        return $store->write(self::NAMESPACE, $key, $plain);
    }
    public function read(string $table, array $owner, string $column): ?string
    {
        $stored = $owner[$column]; if ($stored === null || $stored === '') { return $stored; }
        return (new VaultReferenceStore($this->db, $this->encryptor()))->read(self::NAMESPACE, self::key($table, $owner, $column), $stored);
    }
    public static function decode(#[\SensitiveParameter] string $json): array
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) { throw new \UnexpectedValueException('Domain execution data must be a JSON array or object.'); }
        return $data;
    }
    public function readData(array $owner, string $column): array
    {
        $plain = $this->read('domain_registrar_operations', $owner, $column);
        return $plain === null || $plain === '' ? [] : self::decode($plain);
    }
    public function upgrade(bool $trustedLegacyConfirmed = false): int
    {
        if (!$trustedLegacyConfirmed || $this->db->inTransaction()) { throw new \LogicException('Confirmed standalone maintenance with stopped writers is required.'); }
        $requirements = [];
        foreach (self::requirements() as $table => $columns) { if (KeyBinding::tableExists($this->db, $table)) { $requirements[$table] = $columns; } }
        SecretStorageUpgrade::assertStorage($this->db, $requirements + VaultService::schemaRequirements() + ['security_audit_logs' => ['id', 'actor_user_id', 'event_type', 'target_resource', 'payload']]);
        return $this->db->transaction(function () use ($requirements): int {
            $this->guard(); $plans = [];
            foreach ($requirements as $table => $_) {
                foreach ($this->db->select("SELECT * FROM {$table} ORDER BY id" . $this->db->forUpdate()) as $row) {
                    foreach ($table === 'domains' ? ['epp_code'] : ['payload_json', 'result_json'] as $column) {
                        $value = $row[$column]; if ($value === null || $value === '') { continue; }
                        if (str_starts_with($value, 'vault:')) { $plain = $this->read($table, $row, $column); }
                        else { $plain = $value; }
                        if ($table !== 'domains') { self::decode($plain); }
                        if (str_starts_with($value, 'vault:')) { continue; }
                        $plans[] = [$table, $row, $column, $plain];
                    }
                }
            }
            foreach ($plans as [$table, $row, $column, $plain]) {
                if ($this->db->selectOne('SELECT id FROM vault_secrets WHERE namespace = ? AND secret_key = ?', [self::NAMESPACE, self::key($table, $row, $column)]) !== null) { throw new \LogicException('Legacy domain has a conflicting Vault owner.'); }
                $this->db->update($table, [$column => $this->write($table, $row, $column, $plain)], 'id = :id', ['id' => $row['id']]);
            }
            return count($plans);
        });
    }
}
