<?php

declare(strict_types=1);

namespace Coleza\Domain\Domains\Registrar\Vault;

use Coleza\Domain\Vault\VaultService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;

final class VaultRegistrarSettingsManager
{
    private string $table = 'registrar_settings';

    public function __construct(
        private readonly Connection $db,
        private readonly VaultService $vaultService
    ) {
    }

    public function ensureTables(): void
    {
        $this->vaultService->ensureTable();

        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                registrar_id VARCHAR(64) NOT NULL UNIQUE,
                reseller_id VARCHAR(100) NULL,
                endpoint VARCHAR(255) NULL,
                is_sandbox TINYINT(1) DEFAULT 0,
                custom_settings_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->table,
            $autoInc
        );

        $this->db->statement($sql);
    }

    /**
     * Store registrar credentials: sensitive secrets are encrypted in Vault,
     * non-sensitive metadata is persisted in database.
     */
    public function saveConfiguration(RegistrarConfiguration $config, ?int $actorUserId = null): void
    {
        $this->ensureTables();

        $registrarId = strtolower(trim($config->getRegistrarId()));
        if ($registrarId === '') {
            throw new ValidationException(['registrar_id' => 'Valid registrar_id is required.'], 'Invalid registrar ID');
        }

        $vaultNamespace = "registrar:{$registrarId}";

        // 1. Store sensitive API keys in Vault
        $this->vaultService->setSecret($vaultNamespace, 'api_key', $config->getApiKey(), $actorUserId);
        if ($config->getApiSecret() !== null) {
            $this->vaultService->setSecret($vaultNamespace, 'api_secret', $config->getApiSecret(), $actorUserId);
        }

        // 2. Persist non-sensitive metadata
        $now = date('Y-m-d H:i:s');
        $driver = $this->db->getDriverName();

        if ($driver === 'sqlite') {
            $sql = sprintf(
                'INSERT INTO %s (registrar_id, reseller_id, endpoint, is_sandbox, custom_settings_json, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT(registrar_id) DO UPDATE SET
                    reseller_id = excluded.reseller_id,
                    endpoint = excluded.endpoint,
                    is_sandbox = excluded.is_sandbox,
                    custom_settings_json = excluded.custom_settings_json,
                    updated_at = excluded.updated_at',
                $this->table
            );
        } else {
            $sql = sprintf(
                'INSERT INTO %s (registrar_id, reseller_id, endpoint, is_sandbox, custom_settings_json, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    reseller_id = VALUES(reseller_id),
                    endpoint = VALUES(endpoint),
                    is_sandbox = VALUES(is_sandbox),
                    custom_settings_json = VALUES(custom_settings_json),
                    updated_at = VALUES(updated_at)',
                $this->table
            );
        }

        $this->db->statement($sql, [
            $registrarId,
            $config->getResellerId(),
            $config->getEndpoint(),
            $config->isSandbox() ? 1 : 0,
            json_encode($config->getCustomSettings()),
            $now,
            $now,
        ]);
    }

    /**
     * Retrieve complete configuration, decrypting sensitive credentials from Vault.
     */
    public function getConfiguration(string $registrarId, ?int $actorUserId = null): ?RegistrarConfiguration
    {
        $id = strtolower(trim($registrarId));
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE registrar_id = ?', $this->table), [$id]);
        if ($row === null) {
            return null;
        }

        $vaultNamespace = "registrar:{$id}";
        $apiKey = $this->vaultService->getSecret($vaultNamespace, 'api_key', $actorUserId);
        if ($apiKey === null) {
            return null;
        }

        $apiSecret = $this->vaultService->getSecret($vaultNamespace, 'api_secret', $actorUserId);

        return new RegistrarConfiguration(
            registrarId: $id,
            apiKey: $apiKey,
            apiSecret: $apiSecret,
            resellerId: isset($row['reseller_id']) ? (string) $row['reseller_id'] : null,
            endpoint: isset($row['endpoint']) ? (string) $row['endpoint'] : null,
            isSandbox: (bool) ($row['is_sandbox'] ?? false),
            customSettings: json_decode((string) ($row['custom_settings_json'] ?? '{}'), true) ?: []
        );
    }

    /**
     * Retrieve safe configuration for display with masked credentials.
     *
     * @return array<string, mixed>|null
     */
    public function getMaskedConfiguration(string $registrarId): ?array
    {
        $id = strtolower(trim($registrarId));
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE registrar_id = ?', $this->table), [$id]);
        if ($row === null) {
            return null;
        }

        $vaultNamespace = "registrar:{$id}";
        $maskedKey = $this->vaultService->getMaskedSecret($vaultNamespace, 'api_key');
        $maskedSecret = $this->vaultService->getMaskedSecret($vaultNamespace, 'api_secret');

        return [
            'registrar_id' => $id,
            'api_key' => $maskedKey ?? '••••••••',
            'api_secret' => $maskedSecret,
            'reseller_id' => $row['reseller_id'] ?? null,
            'endpoint' => $row['endpoint'] ?? null,
            'is_sandbox' => (bool) ($row['is_sandbox'] ?? false),
            'custom_settings' => json_decode((string) ($row['custom_settings_json'] ?? '{}'), true) ?: [],
        ];
    }

    /**
     * Delete registrar settings and purge all secrets from Vault.
     */
    public function deleteConfiguration(string $registrarId, ?int $actorUserId = null): bool
    {
        $id = strtolower(trim($registrarId));
        $vaultNamespace = "registrar:{$id}";

        $this->vaultService->deleteSecret($vaultNamespace, 'api_key', $actorUserId);
        $this->vaultService->deleteSecret($vaultNamespace, 'api_secret', $actorUserId);

        $this->db->statement(sprintf('DELETE FROM %s WHERE registrar_id = ?', $this->table), [$id]);
        return true;
    }

    /**
     * @return array<int, string>
     */
    public function listConfiguredRegistrars(): array
    {
        $rows = $this->db->select(sprintf('SELECT registrar_id FROM %s ORDER BY registrar_id ASC', $this->table));
        return array_map(fn (array $r) => (string) $r['registrar_id'], $rows);
    }
}
