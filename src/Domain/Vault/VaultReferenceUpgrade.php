<?php

declare(strict_types=1);
namespace Coleza\Domain\Vault;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Database\PdoSchema;

/** Key-dependent maintenance transfer; writers must be quiescent. */
final class VaultReferenceUpgrade
{
    public function __construct(private Connection $db, private Encryptor $encryptor) {}
    public function upgrade(bool $legacyPlaintextConfirmed = false): int
    {
        VaultReferenceStore::ensureSchema($this->db);
        $hasHooks = $this->db->getDriverName() === 'mysql'
            ? $this->db->selectOne('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', ['webhook_endpoints']) !== null
            : $this->db->selectOne("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'webhook_endpoints'") !== null;
        SecretStorageUpgrade::assertStorage($this->db, ['servers' => ['id', 'hostname', 'auth_secret_encrypted']] + ($hasHooks ? ['webhook_endpoints' => ['id', 'secret']] : []));
        $vault = new VaultService($this->db, $this->encryptor, new \Coleza\Domain\Identity\Audit\AuditLogger($this->db));
        return $this->db->transaction(function () use ($legacyPlaintextConfirmed, $hasHooks, $vault): int {
            KeyBinding::guard($this->db, $this->encryptor);
            $plans = []; $store = new VaultReferenceStore($this->db, $this->encryptor);
            $groups = [['servers', 'auth_secret_encrypted', $this->db->select('SELECT id, hostname, auth_secret_encrypted FROM servers ORDER BY id' . $this->db->forUpdate())]];
            if ($hasHooks) { $groups[] = ['webhook_endpoints', 'secret', $this->db->select('SELECT id, secret FROM webhook_endpoints ORDER BY id' . $this->db->forUpdate())]; }
            foreach ($groups as [$table, $column, $rows]) {
                foreach ($rows as $row) {
                    $value = $row[$column]; if ($value === null) { continue; }
                    $namespace = $table === 'servers' ? 'server' : 'webhook';
                    $key = $table === 'servers' ? hash('sha256', $row['hostname']) : (string) $row['id'];
                    if (str_starts_with($value, 'vault:')) { $store->read($namespace, $key, $value); continue; }
                    if ($table === 'servers' && str_starts_with($value, SecretCipher::PREFIX)) { $plain = (new SecretCipher($this->encryptor))->open($value, 'server:' . $row['hostname']); }
                    else {
                        if (str_starts_with($value, 'enc:') || !$legacyPlaintextConfirmed) { throw new \LogicException('Confirm legacy plaintext provenance or reconcile unknown encoding before Vault transfer.'); }
                        $plain = $value;
                    }
                    $existing = $vault->getSecret($namespace, $key);
                    if ($existing !== null && !hash_equals($existing, $plain)) { throw new \LogicException('Existing Vault secret conflicts with owning legacy record.'); }
                    $plans[] = [$table, $column, $row['id'], $namespace, $key, $plain, $existing !== null];
                }
            }
            foreach ($plans as [$table, $column, $id, $namespace, $key, $plain, $exists]) {
                $ref = $exists ? VaultReferenceStore::reference($key) : $store->write($namespace, $key, $plain);
                $this->db->update($table, [$column => $ref], 'id = :id', ['id' => $id]);
            }
            return count($plans);
        });
    }
}
