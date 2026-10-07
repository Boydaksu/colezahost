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
        $driver = $this->db->getDriverName();
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
            $this->table,
            $autoInc
        );

        $this->db->statement($sql);
    }

    /**
     * Store or replace secret in Vault.
     */
    public function setSecret(string $namespace, string $key, string $plainValue, ?int $actorUserId = null): void
    {
        $this->ensureTable();
        $encrypted = $this->encryptor->encrypt($plainValue);

        $existing = $this->db->selectOne(
            sprintf('SELECT id, version FROM %s WHERE namespace = :ns AND secret_key = :k', $this->table),
            ['ns' => $namespace, 'k' => $key]
        );

        if ($existing === null) {
            $this->db->insert($this->table, [
                'namespace' => $namespace,
                'secret_key' => $key,
                'encrypted_value' => $encrypted,
                'version' => 1,
            ]);

            $this->auditLogger?->log(
                actorUserId: $actorUserId ?? 0,
                eventType: 'VAULT_SECRET_CREATED',
                targetResource: $namespace . ':' . $key
            );
        } else {
            $newVersion = (int) $existing['version'] + 1;
            $this->db->update(
                $this->table,
                [
                    'encrypted_value' => $encrypted,
                    'version' => $newVersion,
                    'last_rotated_at' => date('Y-m-d H:i:s'),
                ],
                'id = :id',
                ['id' => $existing['id']]
            );

            $this->auditLogger?->log(
                actorUserId: $actorUserId ?? 0,
                eventType: 'VAULT_SECRET_ROTATED',
                targetResource: $namespace . ':' . $key,
                payload: ['version' => $newVersion]
            );
        }
    }

    /**
     * Retrieve decrypted secret value.
     */
    public function getSecret(string $namespace, string $key, ?int $actorUserId = null): ?string
    {
        $this->ensureTable();
        $row = $this->db->selectOne(
            sprintf('SELECT encrypted_value FROM %s WHERE namespace = :ns AND secret_key = :k', $this->table),
            ['ns' => $namespace, 'k' => $key]
        );

        if ($row === null) {
            return null;
        }

        $this->auditLogger?->log(
            actorUserId: $actorUserId ?? 0,
            eventType: 'VAULT_SECRET_ACCESSED',
            targetResource: $namespace . ':' . $key
        );

        return $this->encryptor->decrypt((string) $row['encrypted_value']);
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
        $this->ensureTable();
        $this->db->delete($this->table, 'namespace = :ns AND secret_key = :k', ['ns' => $namespace, 'k' => $key]);

        $this->auditLogger?->log(
            actorUserId: $actorUserId ?? 0,
            eventType: 'VAULT_SECRET_DELETED',
            targetResource: $namespace . ':' . $key
        );
    }
}
