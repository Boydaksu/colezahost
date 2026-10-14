<?php
declare(strict_types=1);
namespace Coleza\Domain\Automation\Persistence;

use Coleza\Domain\Vault\{ApplicationKey, Encryptor, KeyBinding, SecretCipher, SecretStorageUpgrade, VaultReferenceStore, VaultService};
use Coleza\Foundation\Database\Connection;

/** Persistent execution snapshots are ciphertext in Vault, never redacted execution inputs. */
final class AutomationSecretStore
{
    public const NAMESPACE = 'automation';
    public function __construct(private Connection $db, private ?Encryptor $encryptor = null) {}
    public static function requirements(): array
    {
        return ['automation_pending' => ['id', 'kind', 'status', 'payload'],
            'automation_run_history' => ['id', 'rule_id', 'correlation_id', 'executed_at', 'payload'],
            'automation_rule_versions' => ['rule_id', 'version_number', 'payload']];
    }
    public static function key(string $table, array $row): string
    {
        if (!isset(self::requirements()[$table])) { throw new \InvalidArgumentException('Unsupported automation secret owner.'); }
        $identity = match ($table) {
            'automation_pending' => [$row['id'], $row['kind']],
            'automation_run_history' => [$row['id']],
            'automation_rule_versions' => [$row['rule_id'], (int) $row['version_number']],
        };
        return hash('sha256', AutomationCodec::encode([$table, $identity]));
    }
    private function encryptor(): Encryptor { return $this->encryptor ??= ApplicationKey::encryptor(ApplicationKey::configured()); }
    public function guard(): void { KeyBinding::guard($this->db, $this->encryptor()); }
    public function write(string $table, array $owner, #[\SensitiveParameter] array $data): string
    {
        if (!$this->db->inTransaction()) { throw new \LogicException('Automation owner and secret must share a transaction.'); }
        SecretStorageUpgrade::assertStorage($this->db, [$table => self::requirements()[$table] ?? []]);
        return (new VaultReferenceStore($this->db, $this->encryptor()))->write(self::NAMESPACE, self::key($table, $owner), AutomationCodec::encode($data));
    }
    public static function validateData(string $table, array $row, #[\SensitiveParameter] string $json): array
    {
        $data = AutomationCodec::decode($json);
        $valid = match ($table) {
            'automation_pending' => ($data['id'] ?? null) === $row['id'] && ($data['status'] ?? null) === $row['status'],
            'automation_run_history' => ($data['run_id'] ?? null) === $row['id'] && ($data['rule_id'] ?? null) === $row['rule_id'] && ($data['correlation_id'] ?? null) === $row['correlation_id'],
            'automation_rule_versions' => ($data['rule_id'] ?? null) === $row['rule_id'] && ($data['version_number'] ?? null) === (int) $row['version_number'],
        };
        if (!$valid) { throw new \UnexpectedValueException('Automation data identity or state mismatch; reconciliation required.'); }
        return $data;
    }
    public function read(string $table, array $row, bool $readOnly = false): array
    {
        $key = self::key($table, $row);
        if (!$readOnly) {
            $json = (new VaultReferenceStore($this->db, $this->encryptor()))->read(self::NAMESPACE, $key, $row['payload']);
        } else {
            $binding = KeyBinding::row($this->db, lock: false);
            if ($binding !== null) { KeyBinding::validate($binding, $this->encryptor()); }
            if (!hash_equals(VaultReferenceStore::reference($key), $row['payload'])) { throw new \RuntimeException('Automation reference does not match its owner.'); }
            $secret = $this->db->selectOne('SELECT * FROM vault_secrets WHERE namespace = ? AND secret_key = ?', [self::NAMESPACE, $key]);
            if ($secret === null) { throw new \RuntimeException('Automation Vault secret is missing.'); }
            $json = (new SecretCipher($this->encryptor()))->open($secret['encrypted_value'], VaultService::scope($secret));
        }
        return self::validateData($table, $row, $json);
    }
    /** Confirm trusted legacy provenance and stop writers; never run this implicitly in a getter. */
    public function upgrade(bool $legacyJsonConfirmed = false): int
    {
        if (!$legacyJsonConfirmed || $this->db->inTransaction()) { throw new \LogicException('Confirmed standalone maintenance is required.'); }
        $requirements = [];
        foreach (self::requirements() as $table => $columns) { if (KeyBinding::tableExists($this->db, $table)) { $requirements[$table] = $columns; } }
        SecretStorageUpgrade::assertStorage($this->db, $requirements + VaultService::schemaRequirements() + ['security_audit_logs' => ['id', 'actor_user_id', 'event_type', 'target_resource', 'payload']]);
        return $this->db->transaction(function () use ($requirements): int {
            $this->guard(); $plans = [];
            foreach ($requirements as $table => $_) {
                foreach ($this->db->select("SELECT * FROM {$table}" . $this->db->forUpdate()) as $row) {
                    if (str_starts_with($row['payload'], 'vault:')) { $this->read($table, $row); continue; }
                    $plans[] = [$table, $row, self::validateData($table, $row, $row['payload'])];
                }
            }
            foreach ($plans as [$table, $row, $data]) {
                if ($this->db->selectOne('SELECT id FROM vault_secrets WHERE namespace = ? AND secret_key = ?', [self::NAMESPACE, self::key($table, $row)]) !== null) { throw new \LogicException('Legacy automation has a conflicting Vault owner.'); }
                $where = $table === 'automation_rule_versions' ? 'rule_id = :id AND version_number = :version' : 'id = :id';
                $params = $table === 'automation_rule_versions' ? ['id' => $row['rule_id'], 'version' => $row['version_number']] : ['id' => $row['id']];
                $this->db->update($table, ['payload' => $this->write($table, $row, $data)], $where, $params);
            }
            return count($plans);
        });
    }
}
