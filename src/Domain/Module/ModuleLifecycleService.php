<?php

declare(strict_types=1);

namespace Coleza\Domain\Module;

use Coleza\Domain\Identity\Audit\AuditLogger;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;

final class ModuleLifecycleService
{
    private string $table = 'installed_modules';

    public function __construct(
        private Connection $db,
        private string $coreVersion = '1.0.0',
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
                module_id VARCHAR(100) NOT NULL UNIQUE,
                name VARCHAR(150) NOT NULL,
                version VARCHAR(50) NOT NULL,
                type VARCHAR(50) NOT NULL,
                is_enabled INT NOT NULL DEFAULT 0,
                manifest LONGTEXT NOT NULL,
                installed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL
            )',
            $this->table,
            $autoInc
        );

        $this->db->statement($sql);
    }

    /**
     * Discover modules from modules directory by reading manifest.json files.
     *
     * @return array<string, ModuleManifest> Map of module_id => ModuleManifest
     */
    public function discover(string $modulesDirectory): array
    {
        $discovered = [];
        if (!is_dir($modulesDirectory)) {
            return $discovered;
        }

        $dirs = glob(rtrim($modulesDirectory, '/\\') . '/*', GLOB_ONLYDIR);
        if (!$dirs) {
            return $discovered;
        }

        foreach ($dirs as $dir) {
            $manifestFile = $dir . '/manifest.json';
            if (file_exists($manifestFile)) {
                $content = @file_get_contents($manifestFile);
                if ($content !== false) {
                    $json = json_decode($content, true);
                    if (is_array($json)) {
                        try {
                            $manifest = ModuleManifest::fromArray($json);
                            $discovered[$manifest->id] = $manifest;
                        } catch (ValidationException) {
                            // Skip invalid manifests during discovery
                        }
                    }
                }
            }
        }

        return $discovered;
    }

    /**
     * Install module: validates manifest, core compatibility, registers in DB.
     */
    public function install(ModuleManifest $manifest, ?int $actorUserId = null): void
    {
        $this->ensureTable();

        // Compatibility check
        if (version_compare($this->coreVersion, $manifest->minCoreVersion, '<')) {
            throw new ValidationException(
                ['compatibility' => [sprintf(
                    'Module [%s] requires Coleza Host core version >= %s (Current: %s).',
                    $manifest->id,
                    $manifest->minCoreVersion,
                    $this->coreVersion
                )]],
                'Core version incompatible.'
            );
        }

        $existing = $this->db->selectOne(
            sprintf('SELECT id FROM %s WHERE module_id = :id', $this->table),
            ['id' => $manifest->id]
        );

        if ($existing !== null) {
            throw new ValidationException(
                ['module' => [sprintf('Module [%s] is already installed.', $manifest->id)]]
            );
        }

        $this->db->insert($this->table, [
            'module_id' => $manifest->id,
            'name' => $manifest->name,
            'version' => $manifest->version,
            'type' => $manifest->type,
            'is_enabled' => 0, // Installed disabled by default
            'manifest' => json_encode($manifest->toArray(), JSON_UNESCAPED_SLASHES),
        ]);

        $this->auditLogger?->log(
            actorUserId: $actorUserId ?? 0,
            eventType: 'MODULE_INSTALLED',
            targetResource: 'module:' . $manifest->id,
            payload: ['version' => $manifest->version]
        );
    }

    /**
     * Enable installed module.
     */
    public function enable(string $moduleId, ?int $actorUserId = null): void
    {
        $this->ensureTable();
        $record = $this->db->selectOne(
            sprintf('SELECT id FROM %s WHERE module_id = :id', $this->table),
            ['id' => $moduleId]
        );

        if ($record === null) {
            throw new ValidationException(['module' => ['Module is not installed.']]);
        }

        $this->db->update(
            $this->table,
            ['is_enabled' => 1, 'updated_at' => date('Y-m-d H:i:s')],
            'id = :id',
            ['id' => $record['id']]
        );

        $this->auditLogger?->log(
            actorUserId: $actorUserId ?? 0,
            eventType: 'MODULE_ENABLED',
            targetResource: 'module:' . $moduleId
        );
    }

    /**
     * Disable module (Extension Constitution: disable != uninstall).
     */
    public function disable(string $moduleId, ?int $actorUserId = null): void
    {
        $this->ensureTable();
        $record = $this->db->selectOne(
            sprintf('SELECT id FROM %s WHERE module_id = :id', $this->table),
            ['id' => $moduleId]
        );

        if ($record === null) {
            throw new ValidationException(['module' => ['Module is not installed.']]);
        }

        $this->db->update(
            $this->table,
            ['is_enabled' => 0, 'updated_at' => date('Y-m-d H:i:s')],
            'id = :id',
            ['id' => $record['id']]
        );

        $this->auditLogger?->log(
            actorUserId: $actorUserId ?? 0,
            eventType: 'MODULE_DISABLED',
            targetResource: 'module:' . $moduleId
        );
    }

    /**
     * Check if module is enabled.
     */
    public function isEnabled(string $moduleId): bool
    {
        $this->ensureTable();
        $record = $this->db->selectOne(
            sprintf('SELECT is_enabled FROM %s WHERE module_id = :id', $this->table),
            ['id' => $moduleId]
        );

        return $record !== null && (int) $record['is_enabled'] === 1;
    }

    /**
     * Query active modules by capability (e.g. 'cpanel.provision', 'stripe.checkout').
     *
     * @return array<int, string> List of enabled module IDs providing capability
     */
    public function getModulesWithCapability(string $capability): array
    {
        $this->ensureTable();
        $rows = $this->db->select(
            sprintf('SELECT module_id, manifest FROM %s WHERE is_enabled = 1', $this->table)
        );

        $matching = [];
        foreach ($rows as $row) {
            $manifest = json_decode((string) $row['manifest'], true);
            if (is_array($manifest) && in_array($capability, $manifest['capabilities'] ?? [], true)) {
                $matching[] = (string) $row['module_id'];
            }
        }

        return $matching;
    }
}
