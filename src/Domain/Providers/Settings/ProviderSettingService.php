<?php

declare(strict_types=1);

namespace Coleza\Domain\Providers\Settings;

use Coleza\Domain\Vault\VaultService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use RuntimeException;

final class ProviderSettingService
{
    private string $table = 'provider_module_settings';

    /** @var array<string, ProviderSettingSchema> */
    private array $schemas = [];

    public function __construct(
        private Connection $db,
        private VaultService $vaultService
    ) {
        // Register standard schemas by default
        $this->registerSchema(ProviderSettingSchema::forCpanel());
        $this->registerSchema(ProviderSettingSchema::forDirectAdmin());
    }

    public function registerSchema(ProviderSettingSchema $schema): void
    {
        $this->schemas[$schema->getProviderSlug()] = $schema;
    }

    public function getSchema(string $providerSlug): ?ProviderSettingSchema
    {
        return $this->schemas[$providerSlug] ?? null;
    }

    public function ensureTables(): void
    {
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                provider_slug VARCHAR(100) NOT NULL,
                setting_key VARCHAR(100) NOT NULL,
                setting_value TEXT NULL,
                is_secret INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(provider_slug, setting_key)
            )',
            $this->table,
            $autoInc
        );
        $this->db->statement($sql);

        // Ensure Vault table exists as well
        $this->vaultService->ensureTable();
    }

    /**
     * Save provider module configuration.
     * Sensitive secrets are routed to Vault; non-sensitive settings are saved to database.
     *
     * @param array<string, mixed> $values
     */
    public function saveSettings(string $providerSlug, array $values, ?int $actorUserId = null): void
    {
        $this->ensureTables();
        $schema = $this->getSchema($providerSlug);

        // Validate values if schema exists
        if ($schema !== null) {
            foreach ($schema->all() as $key => $def) {
                if (array_key_exists($key, $values)) {
                    $def->validate($values[$key]);
                } elseif ($def->isRequired()) {
                    // Check if already stored
                    if (!$this->hasStoredValue($providerSlug, $key)) {
                        throw new ValidationException(
                            [$key => "Setting '{$def->getLabel()}' is required."],
                            'Missing required setting'
                        );
                    }
                }
            }
        }

        $now = date('Y-m-d H:i:s');
        $vaultNamespace = "provider:{$providerSlug}";

        foreach ($values as $key => $val) {
            $isSecret = $this->isSecretKey($providerSlug, $key);

            if ($isSecret) {
                // If a new secret value is provided (not empty and not masked placeholder)
                if (is_string($val) && $val !== '' && !str_contains($val, '••••') && !str_contains($val, '****')) {
                    $this->vaultService->setSecret($vaultNamespace, $key, $val, $actorUserId);

                    $this->upsertSettingRecord($providerSlug, $key, null, 1, $now);
                }
            } else {
                $strVal = is_bool($val) ? ($val ? '1' : '0') : (string)$val;
                $this->upsertSettingRecord($providerSlug, $key, $strVal, 0, $now);
            }
        }
    }

    /**
     * Retrieve public representation of settings (safe for admin UI and API responses).
     * Secrets are strictly masked and never revealed.
     *
     * @return array<string, mixed>
     */
    public function getPublicSettings(string $providerSlug): array
    {
        $this->ensureTables();
        $schema = $this->getSchema($providerSlug);
        $defaults = $schema ? $schema->getDefaults() : [];

        $rows = $this->db->select(
            sprintf('SELECT setting_key, setting_value, is_secret FROM %s WHERE provider_slug = ?', $this->table),
            [$providerSlug]
        );

        $settings = $defaults;
        $vaultNamespace = "provider:{$providerSlug}";

        foreach ($rows as $row) {
            $key = (string)$row['setting_key'];
            $isSecret = (bool)$row['is_secret'];

            if ($isSecret) {
                $masked = $this->vaultService->getMaskedSecret($vaultNamespace, $key);
                $settings[$key] = $masked ?? '••••••••';
            } else {
                $settings[$key] = $row['setting_value'];
            }
        }

        return $settings;
    }

    /**
     * Resolve effective configuration for runtime execution by provider adapters.
     * Combines schema defaults, non-sensitive database entries, and decrypted Vault secrets.
     */
    public function resolveEffectiveConfig(string $providerSlug, ?int $actorUserId = null): ResolvedProviderConfig
    {
        $this->ensureTables();
        $schema = $this->getSchema($providerSlug);
        $defaults = $schema ? $schema->getDefaults() : [];

        $rows = $this->db->select(
            sprintf('SELECT setting_key, setting_value, is_secret FROM %s WHERE provider_slug = ?', $this->table),
            [$providerSlug]
        );

        $resolvedValues = $defaults;
        $secretKeys = [];
        $vaultNamespace = "provider:{$providerSlug}";

        foreach ($rows as $row) {
            $key = (string)$row['setting_key'];
            $isSecret = (bool)$row['is_secret'];

            if ($isSecret) {
                $secretKeys[] = $key;
                $secretVal = $this->vaultService->getSecret($vaultNamespace, $key, $actorUserId);
                if ($secretVal !== null) {
                    $resolvedValues[$key] = $secretVal;
                }
            } else {
                $resolvedValues[$key] = $row['setting_value'];
            }
        }

        // Also add schema declared secrets that might be stored
        if ($schema !== null) {
            foreach ($schema->all() as $key => $def) {
                if ($def->isSecret()) {
                    $secretKeys[] = $key;
                    if (!isset($resolvedValues[$key])) {
                        $secretVal = $this->vaultService->getSecret($vaultNamespace, $key, $actorUserId);
                        if ($secretVal !== null) {
                            $resolvedValues[$key] = $secretVal;
                        }
                    }
                }
            }
        }

        return new ResolvedProviderConfig(
            providerSlug: $providerSlug,
            values: $resolvedValues,
            secretKeys: array_unique($secretKeys)
        );
    }

    /**
     * Delete provider configuration and purge secrets from Vault.
     */
    public function deleteSettings(string $providerSlug, ?int $actorUserId = null): void
    {
        $this->ensureTables();
        $vaultNamespace = "provider:{$providerSlug}";

        $rows = $this->db->select(
            sprintf('SELECT setting_key, is_secret FROM %s WHERE provider_slug = ?', $this->table),
            [$providerSlug]
        );

        foreach ($rows as $row) {
            if ((bool)$row['is_secret']) {
                $this->vaultService->deleteSecret($vaultNamespace, (string)$row['setting_key'], $actorUserId);
            }
        }

        $this->db->statement(
            sprintf('DELETE FROM %s WHERE provider_slug = ?', $this->table),
            [$providerSlug]
        );
    }

    private function isSecretKey(string $providerSlug, string $key): bool
    {
        $schema = $this->getSchema($providerSlug);
        if ($schema !== null && $schema->has($key)) {
            return $schema->isSecret($key);
        }

        $keyLower = strtolower($key);
        return str_contains($keyLower, 'token')
            || str_contains($keyLower, 'secret')
            || str_contains($keyLower, 'password')
            || str_contains($keyLower, 'key');
    }

    private function hasStoredValue(string $providerSlug, string $key): bool
    {
        $row = $this->db->selectOne(
            sprintf('SELECT id, is_secret FROM %s WHERE provider_slug = ? AND setting_key = ?', $this->table),
            [$providerSlug, $key]
        );

        if ($row === null) {
            return false;
        }

        if ((bool)$row['is_secret']) {
            return $this->vaultService->getSecret("provider:{$providerSlug}", $key) !== null;
        }

        return true;
    }

    private function upsertSettingRecord(
        string $providerSlug,
        string $key,
        ?string $value,
        int $isSecret,
        string $timestamp
    ): void {
        $existing = $this->db->selectOne(
            sprintf('SELECT id FROM %s WHERE provider_slug = ? AND setting_key = ?', $this->table),
            [$providerSlug, $key]
        );

        if ($existing) {
            $this->db->statement(
                sprintf('UPDATE %s SET setting_value = ?, is_secret = ?, updated_at = ? WHERE id = ?', $this->table),
                [$value, $isSecret, $timestamp, (int)$existing['id']]
            );
        } else {
            $this->db->statement(
                sprintf('INSERT INTO %s (provider_slug, setting_key, setting_value, is_secret, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', $this->table),
                [$providerSlug, $key, $value, $isSecret, $timestamp, $timestamp]
            );
        }
    }
}
