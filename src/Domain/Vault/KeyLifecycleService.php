<?php
declare(strict_types=1);
namespace Coleza\Domain\Vault;

use Coleza\Foundation\Database\Connection;
use Coleza\Domain\Identity\Audit\AuditLogger;

/** Explicit maintenance on canonical storage; no DDL, no key file replacement, no environment mutation. */
final class KeyLifecycleService
{
    public function __construct(private Connection $db, private Encryptor $encryptor) {}

    public static function requiresVerification(Connection $db): bool
    {
        if (KeyBinding::row($db, lock: false) !== null) { return true; }
        foreach (['vault_secrets' => 'encrypted_value', 'servers' => 'auth_secret_encrypted', 'user_two_factor' => 'secret'] as $table => $column) {
            if (KeyBinding::tableExists($db, $table) && $db->selectOne("SELECT 1 FROM {$table} WHERE {$column} IS NOT NULL AND {$column} <> '' LIMIT 1") !== null) { return true; }
        }
        return KeyBinding::tableExists($db, 'system_settings') && $db->selectOne("SELECT 1 FROM system_settings WHERE setting_value IS NOT NULL AND setting_value <> '' AND (setting_key = 'mail.password' OR is_encrypted = 1) LIMIT 1") !== null;
    }

    private function prepare(): void
    {
        SecretStorageUpgrade::assertStorage($this->db, [
            'system_settings' => ['id', 'setting_key', 'setting_value', 'is_encrypted'],
            'servers' => ['id', 'hostname', 'auth_secret_encrypted'],
            'user_two_factor' => ['id', 'user_id', 'secret'],
        ] + VaultService::schemaRequirements());
        if (!\Coleza\Domain\Identity\IdentityPersistence::installed($this->db, ['system_settings' => ['id', 'setting_key', 'setting_value', 'is_encrypted']], installation: false, unique: ['system_settings' => 'setting_key'])) { throw new \LogicException('Install canonical settings before binding a key.'); }
        VaultPersistence::prepare($this->db);
        if (KeyBinding::tableExists($this->db, 'webhook_endpoints')) { SecretStorageUpgrade::assertStorage($this->db, ['webhook_endpoints' => ['id', 'secret']]); }
    }

    private function maintenance(bool $confirmed): void
    {
        if (!$confirmed || $this->db->inTransaction()) { throw new \LogicException('Key lifecycle requires confirmed maintenance and its own durable transaction.'); }
        $this->prepare();
        SecretStorageUpgrade::assertStorage($this->db, ['security_audit_logs' => ['id', 'actor_user_id', 'impersonator_user_id', 'event_type', 'target_resource', 'ip_address', 'user_agent', 'payload', 'created_at']]);
    }

    public function initialize(bool $maintenanceConfirmed = false): array
    {
        $this->maintenance($maintenanceConfirmed);
        return $this->db->transaction(function (): array {
            $row = KeyBinding::row($this->db);
            if ($row !== null) { KeyBinding::validate($row, $this->encryptor); }
            // Reserve binding first: all bound runtime writers take this same lock.
            if ($row === null) {
                $this->db->insert('system_settings', ['setting_key' => KeyBinding::SETTING, 'setting_value' => (new SecretCipher($this->encryptor))->seal(KeyBinding::PROBE, KeyBinding::SCOPE), 'is_encrypted' => 1]);
            }
            $inventory = $this->inventory();
            if ($row === null) { (new AuditLogger($this->db))->log(0, 'APPLICATION_KEY_BOUND', 'application-key', payload: ['key_id' => $this->encryptor->fingerprint(), 'counts' => $inventory['counts']]); }
            return ['key_id' => $this->encryptor->fingerprint(), 'counts' => $inventory['counts']];
        });
    }

    /** Deep verification is read-only, including audit. Caller must keep restore/maintenance writers stopped. */
    public function verify(): array
    {
        $this->prepare();
        $row = KeyBinding::row($this->db, lock: false);
        if ($row === null) { throw new \LogicException('Database has no application key binding; explicit initialization required.'); }
        KeyBinding::validate($row, $this->encryptor);
        return ['key_id' => $this->encryptor->fingerprint(), 'counts' => $this->inventory(lock: false)['counts']];
    }

    public function rotateToFile(string $newKeyFile, string $applicationRoot, bool $maintenanceConfirmed = false): array
    {
        $this->maintenance($maintenanceConfirmed);
        // Stage and protect the replacement before opening the database transaction. A crash cannot lose the new key.
        $replacement = ApplicationKey::encryptor(PrivateKeyFile::load($newKeyFile, $applicationRoot));
        if (hash_equals($this->encryptor->fingerprint(), $replacement->fingerprint())) { throw new \LogicException('Replacement key must differ from the current key.'); }
        return $this->db->transaction(function () use ($replacement): array {
            $binding = KeyBinding::row($this->db);
            if ($binding === null) { throw new \LogicException('Bind and verify the existing key before rotation.'); }
            KeyBinding::validate($binding, $this->encryptor);
            $inventory = $this->inventory($replacement);
            foreach ($inventory['plans'] as [$table, $id, $data]) {
                $this->db->update($table, $data, 'id = :id', ['id' => $id]);
            }
            $this->db->update('system_settings', ['setting_value' => (new SecretCipher($replacement))->seal(KeyBinding::PROBE, KeyBinding::SCOPE), 'is_encrypted' => 1], 'id = :id', ['id' => $binding['id']]);
            $result = ['previous_key_id' => $this->encryptor->fingerprint(), 'key_id' => $replacement->fingerprint(), 'counts' => $inventory['counts']];
            (new AuditLogger($this->db))->log(0, 'APPLICATION_KEY_ROTATED', 'application-key', payload: $result);
            return $result;
        });
    }

    private function inventory(?Encryptor $replacement = null, bool $lock = true): array
    {
        $suffix = $lock ? $this->db->forUpdate() : '';
        $old = new SecretCipher($this->encryptor); $new = $replacement === null ? null : new SecretCipher($replacement);
        $plans = []; $counts = ['vault' => 0, 'smtp' => 0, 'totp' => 0, 'legacy_server' => 0, 'references' => 0]; $identities = [];
        foreach ($this->db->select('SELECT * FROM vault_secrets ORDER BY id' . $suffix) as $row) {
            if ((int) $row['version'] < 1) { throw new \LogicException('Invalid Vault version.'); }
            $scope = VaultService::scope($row); $stored = (string) $row['encrypted_value'];
            $plain = str_starts_with($stored, 'enc:') ? $old->open($stored, $scope) : $this->encryptor->decrypt($stored);
            $identities[$scope] = true; $counts['vault']++;
            if ($new !== null) { $plans[] = ['vault_secrets', $row['id'], ['encrypted_value' => $new->seal($plain, $scope), 'version' => (int) $row['version'] + 1, 'last_rotated_at' => date('Y-m-d H:i:s')]]; }
        }
        foreach ($this->db->select('SELECT id, setting_key, setting_value, is_encrypted FROM system_settings ORDER BY id' . $suffix) as $row) {
            if ($row['setting_key'] === KeyBinding::SETTING) { continue; }
            if ($row['setting_key'] === 'cron.token') {
                if ((int) $row['is_encrypted'] !== 0 || !\Coleza\Domain\Installer\CronSetupService::isTokenHash((string) $row['setting_value'])) { throw new \LogicException('Explicitly upgrade the legacy cron token before key lifecycle.'); }
                continue; // One-way authentication hash is independent of the application encryption key.
            }
            if ($row['setting_key'] !== 'mail.password') {
                if ((int) $row['is_encrypted'] === 1) { throw new \LogicException('Unknown encrypted setting scope; reconcile before key lifecycle.'); }
                continue;
            }
            if ($row['setting_value'] === null || $row['setting_value'] === '') { continue; }
            $plain = $old->open($row['setting_value'], 'smtp:'); $counts['smtp']++;
            if ($new !== null) { $plans[] = ['system_settings', $row['id'], ['setting_value' => $new->seal($plain, 'smtp:'), 'is_encrypted' => 1]]; }
        }
        foreach ($this->db->select('SELECT id, user_id, secret FROM user_two_factor ORDER BY id' . $suffix) as $row) {
            $scope = 'totp:' . $row['user_id']; $plain = $old->open((string) $row['secret'], $scope); $counts['totp']++;
            if ($new !== null) {
                $sealed = $new->seal($plain, $scope);
                if (strlen($sealed) > 128) { throw new \LogicException('TOTP ciphertext exceeds installed capacity.'); }
                $plans[] = ['user_two_factor', $row['id'], ['secret' => $sealed]];
            }
        }
        $groups = [['servers', 'auth_secret_encrypted', 'server', $this->db->select('SELECT id, hostname, auth_secret_encrypted FROM servers ORDER BY id' . $suffix)]];
        if (KeyBinding::tableExists($this->db, 'webhook_endpoints')) { $groups[] = ['webhook_endpoints', 'secret', 'webhook', $this->db->select('SELECT id, secret FROM webhook_endpoints ORDER BY id' . $suffix)]; }
        foreach ($groups as [$table, $column, $namespace, $rows]) {
            foreach ($rows as $row) {
                $stored = $row[$column]; if ($stored === null || $stored === '') { continue; }
                if (str_starts_with($stored, 'vault:')) {
                    $key = $namespace === 'server' ? hash('sha256', $row['hostname']) : (string) $row['id'];
                    if (!hash_equals(VaultReferenceStore::reference($key), $stored) || !isset($identities[VaultService::scope(['namespace' => $namespace, 'secret_key' => $key])])) { throw new \RuntimeException('Secret reference does not match an existing Vault owner.'); }
                    $counts['references']++; continue;
                }
                if ($namespace !== 'server') { throw new \LogicException('Transfer legacy webhook secrets to Vault before key lifecycle.'); }
                $scope = 'server:' . $row['hostname']; $plain = $old->open($stored, $scope); $counts['legacy_server']++;
                if ($new !== null) { $plans[] = [$table, $row['id'], [$column => $new->seal($plain, $scope)]]; }
            }
        }
        return ['plans' => $plans, 'counts' => $counts];
    }
}
