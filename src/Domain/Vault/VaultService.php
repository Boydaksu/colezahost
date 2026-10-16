<?php

declare(strict_types=1);

namespace Coleza\Domain\Vault;

use Coleza\Domain\Identity\Audit\AuditLogger;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;

final class VaultService
{
    private string $table = 'vault_secrets';

    public function __construct(
        private Connection $db,
        private Encryptor $encryptor,
        private ?AuditLogger $auditLogger = null
    ) {
    }

    public function ensureTable(): void
    {
        self::ensureSchema($this->db);
    }
    public function assertConnection(Connection $db): void
    {
        if($db->getPdo()!==$this->db->getPdo()){throw new \LogicException('Vault database differs from scoped extension context.');}
    }

    /** Required columns for metadata-only preflight of dependent schemas. */
    public static function schemaRequirements(): array
    {
        return ['vault_secrets' => ['id', 'namespace', 'secret_key', 'encrypted_value', 'version', 'last_rotated_at', 'created_at']];
    }

    /** Install schema without constructing an encryptor or accessing secret values. */
    public static function ensureSchema(Connection $db, bool $installSchema = false): void
    {
        if (\Coleza\Foundation\Database\PdoSchema::useInstalledSchema($db, self::schemaRequirements(), $installSchema)) { return; }
        \Coleza\Foundation\Database\PdoSchema::autoIncrement($db->getPdo());
        $table = 'vault_secrets';
        $driver = $db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                namespace VARCHAR(50) NOT NULL,
                secret_key VARCHAR(100) NOT NULL,
                encrypted_value LONGTEXT NOT NULL,
                version INT NOT NULL DEFAULT 1,
                last_rotated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(namespace, secret_key)
            )',
            $table,
            $autoInc
        );

        $db->statement($sql . ($driver === 'mysql' ? ' ENGINE=InnoDB' : ''));
    }

    /**
     * Store or replace secret in Vault.
     */
    private function prepare(): void
    {
        $this->ensureTable(); VaultPersistence::prepare($this->db);
        if ($this->auditLogger !== null) {
            $this->auditLogger->ensureTable();
            SecretStorageUpgrade::assertStorage($this->db, ['security_audit_logs' => ['id', 'actor_user_id', 'event_type', 'target_resource', 'payload']]);
        }
    }
    public static function scope(array $row): string
    {
        return json_encode(['vault', $row['namespace'], $row['secret_key']], JSON_THROW_ON_ERROR);
    }
    private static function validateIdentity(string $namespace, string $key): void
    {
        if ($namespace === '' || $key === '' || strlen($namespace) > 50 || strlen($key) > 100) { throw new \InvalidArgumentException('Invalid Vault namespace/key length.'); }
    }
    private static function resource(array $row): string
    {
        $value = $row['namespace'] . ':' . $row['secret_key'];
        return strlen($value) <= 100 ? $value : 'vault:' . hash('sha256', self::scope($row));
    }
    public function setSecret(string $namespace, string $key, #[\SensitiveParameter] string $plainValue, ?int $actorUserId = null): void
    {
        self::validateIdentity($namespace, $key); $this->prepare();
        $this->db->transaction(function () use ($namespace, $key, $plainValue, $actorUserId): void {
            KeyBinding::guard($this->db, $this->encryptor);
            $suffix = $this->db->getDriverName() === 'mysql'
                ? ' ON DUPLICATE KEY UPDATE version = version + 1'
                : ' ON CONFLICT(namespace, secret_key) DO UPDATE SET version = version + 1';
            // Reserve the unique row under its write lock; no placeholder can commit independently.
            $this->db->statement('INSERT INTO vault_secrets (namespace, secret_key, encrypted_value, version) VALUES (?, ?, ?, 1)' . $suffix, [$namespace, $key, '']);
            $row = $this->db->selectOne('SELECT id, namespace, secret_key, version FROM vault_secrets WHERE namespace = ? AND secret_key = ?' . $this->db->forUpdate(), [$namespace, $key]);
            if ($row === null) { throw new \LogicException('Vault reservation lost.'); }
            $this->db->update($this->table, ['encrypted_value' => (new SecretCipher($this->encryptor))->seal($plainValue, self::scope($row)), 'last_rotated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $row['id']]);
            $created = (int) $row['version'] === 1;
            $this->auditLogger?->log(actorUserId: $actorUserId ?? 0, eventType: $created ? 'VAULT_SECRET_CREATED' : 'VAULT_SECRET_ROTATED', targetResource: self::resource($row), payload: $created ? [] : ['version' => (int) $row['version']]);
        });
    }
    private function decode(array $row): string
    {
        $value = (string) $row['encrypted_value'];
        if (str_starts_with($value, 'enc:')) { return (new SecretCipher($this->encryptor))->open($value, self::scope($row)); }
        // Existing Vault records are authenticated legacy GCM ciphertext, never plaintext.
        return $this->encryptor->decrypt($value);
    }
    public function getSecret(string $namespace, string $key, ?int $actorUserId = null): ?string
    {
        self::validateIdentity($namespace, $key); $this->prepare();
        return $this->db->transaction(function () use ($namespace, $key, $actorUserId): ?string {
            KeyBinding::guard($this->db, $this->encryptor);
            $row = $this->db->selectOne('SELECT id, namespace, secret_key, encrypted_value FROM vault_secrets WHERE namespace = ? AND secret_key = ?' . $this->db->forUpdate(), [$namespace, $key]);
            if ($row === null) { return null; }
            $plain = $this->decode($row);
            $this->auditLogger?->log(actorUserId: $actorUserId ?? 0, eventType: 'VAULT_SECRET_ACCESSED', targetResource: self::resource($row));
            return $plain;
        });
    }
    public function upgradeLegacyCiphertexts(?int $actorUserId = null): int
    {
        $this->prepare();
        return $this->db->transaction(function () use ($actorUserId): int {
            KeyBinding::guard($this->db, $this->encryptor);
            $changes = [];
            foreach ($this->db->select('SELECT * FROM vault_secrets ORDER BY id' . $this->db->forUpdate()) as $row) {
                $plain = $this->decode($row);
                if (str_starts_with($row['encrypted_value'], SecretCipher::PREFIX)) { continue; }
                $changes[] = [$row, (new SecretCipher($this->encryptor))->seal($plain, self::scope($row))];
            }
            foreach ($changes as [$row, $cipher]) {
                $this->db->update($this->table, ['encrypted_value' => $cipher], 'id = :id', ['id' => $row['id']]);
                $this->auditLogger?->log(actorUserId: $actorUserId ?? 0, eventType: 'VAULT_SECRET_RESEALED', targetResource: self::resource($row));
            }
            return count($changes);
        });
    }

    /**
     * Get masked display representation of a secret.
     * In accordance with Security Constitution: "Secrets masked, replace/rotate rather than reveal."
     */
    public function getMaskedSecret(string $namespace, string $key): ?string
    {
        $this->ensureTable();
        $secret = $this->getSecret($namespace, $key);
        if ($secret === null) {
            return null;
        }

        $len = strlen($secret);
        if ($len <= 4) {
            return str_repeat('•', 8);
        }

        $visibleStart = min(2, (int) floor($len / 4));
        $visibleEnd = min(2, (int) floor($len / 4));
        $maskedLength = max(4, $len - ($visibleStart + $visibleEnd));

        return substr($secret, 0, $visibleStart) . str_repeat('•', $maskedLength) . substr($secret, -$visibleEnd);
    }

    /**
     * Delete secret from Vault.
     */
    public function deleteSecret(string $namespace, string $key, ?int $actorUserId = null): void
    {
        self::validateIdentity($namespace, $key); $this->prepare();
        $this->db->transaction(function () use ($namespace, $key, $actorUserId): void {
            KeyBinding::guard($this->db, $this->encryptor);
            $this->db->delete($this->table, 'namespace = :ns AND secret_key = :k', ['ns' => $namespace, 'k' => $key]);
            $this->auditLogger?->log(actorUserId: $actorUserId ?? 0, eventType: 'VAULT_SECRET_DELETED', targetResource: strlen($namespace . ':' . $key) <= 100 ? $namespace . ':' . $key : 'vault:' . hash('sha256', json_encode(['vault', $namespace, $key], JSON_THROW_ON_ERROR)));
        });
    }
}
