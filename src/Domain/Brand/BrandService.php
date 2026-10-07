<?php

declare(strict_types=1);

namespace Coleza\Domain\Brand;

use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;

final class BrandService
{
    private string $table = 'brands';

    public function __construct(private Connection $db)
    {
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
                name VARCHAR(100) NOT NULL,
                code VARCHAR(50) NOT NULL UNIQUE,
                domain VARCHAR(191) NULL,
                default_locale VARCHAR(10) NOT NULL DEFAULT "tr_TR",
                default_currency VARCHAR(3) NOT NULL DEFAULT "TRY",
                is_active INT NOT NULL DEFAULT 1,
                settings LONGTEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->table,
            $autoInc
        );

        $this->db->statement($sql);
    }

    /**
     * Get or create the single-active brand of the installation.
     * V1 specification enforces single-active brand constraint.
     *
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    public function getActiveBrand(array $defaults = []): array
    {
        $this->ensureTable();

        $active = $this->db->selectOne(
            sprintf('SELECT * FROM %s WHERE is_active = 1 ORDER BY id ASC', $this->table)
        );

        if ($active !== null) {
            if (!empty($active['settings'])) {
                $active['settings'] = json_decode((string) $active['settings'], true) ?: [];
            } else {
                $active['settings'] = [];
            }
            return $active;
        }

        // Initialize default primary brand if none exists
        $name = $defaults['name'] ?? 'Coleza Host';
        $code = $defaults['code'] ?? 'default';
        $domain = $defaults['domain'] ?? 'localhost';
        $locale = $defaults['default_locale'] ?? 'tr_TR';
        $currency = $defaults['default_currency'] ?? 'TRY';
        $settings = $defaults['settings'] ?? [];

        $id = $this->db->insert($this->table, [
            'name' => $name,
            'code' => $code,
            'domain' => $domain,
            'default_locale' => $locale,
            'default_currency' => $currency,
            'is_active' => 1,
            'settings' => json_encode($settings, JSON_UNESCAPED_SLASHES),
        ]);

        return [
            'id' => (int) $id,
            'name' => $name,
            'code' => $code,
            'domain' => $domain,
            'default_locale' => $locale,
            'default_currency' => $currency,
            'is_active' => 1,
            'settings' => $settings,
        ];
    }

    /**
     * Update active brand configuration.
     *
     * @param array<string, mixed> $updates
     * @return array<string, mixed>
     */
    public function updateActiveBrand(array $updates): array
    {
        $this->ensureTable();
        $brand = $this->getActiveBrand();

        $fields = [];
        $params = ['id' => $brand['id']];

        if (isset($updates['name'])) {
            $fields['name'] = trim((string) $updates['name']);
        }
        if (isset($updates['domain'])) {
            $fields['domain'] = trim((string) $updates['domain']);
        }
        if (isset($updates['default_locale'])) {
            $fields['default_locale'] = trim((string) $updates['default_locale']);
        }
        if (isset($updates['default_currency'])) {
            $fields['default_currency'] = strtoupper(trim((string) $updates['default_currency']));
        }
        if (isset($updates['settings']) && is_array($updates['settings'])) {
            $mergedSettings = array_merge($brand['settings'], $updates['settings']);
            $fields['settings'] = json_encode($mergedSettings, JSON_UNESCAPED_SLASHES);
        }

        if (!empty($fields)) {
            $this->db->update($this->table, $fields, 'id = :id', $params);
        }

        return $this->getActiveBrand();
    }

    /**
     * In V1, creating a second active brand is strictly forbidden by contract.
     */
    public function createBrand(string $name, string $code): int
    {
        $this->ensureTable();
        $existingCount = (int) $this->db->selectOne(
            sprintf('SELECT COUNT(*) as cnt FROM %s WHERE is_active = 1', $this->table)
        )['cnt'];

        if ($existingCount >= 1) {
            throw new ValidationException(
                ['brand' => ['V1 architecture strictly enforces a single-active Brand. Multi-brand isolation is scheduled for V1.1.']],
                'Cannot create multiple active brands in V1 release family.'
            );
        }

        return (int) $this->db->insert($this->table, [
            'name' => $name,
            'code' => $code,
            'is_active' => 1,
        ]);
    }
}
