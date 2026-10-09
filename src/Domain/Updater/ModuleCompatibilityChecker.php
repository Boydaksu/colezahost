<?php

declare(strict_types=1);

namespace Coleza\Domain\Updater;

use Coleza\Domain\Module\ModuleLifecycleService;
use Coleza\Domain\Module\ModuleManifest;
use Coleza\Foundation\Database\Connection;

/**
 * Assesses whether currently installed and active modules are compatible
 * with the target core update version.
 */
final class ModuleCompatibilityChecker
{
    public function __construct(
        private Connection $db,
        private ?ModuleLifecycleService $lifecycleService = null
    ) {
    }

    /**
     * Checks all installed and enabled modules against target core version and manifest requirements.
     *
     * @param string $targetCoreVersion Target version of Coleza Host core
     * @param array<string, string> $targetRequiredModules Minimum module versions expected by core update
     * @return array{
     *     compatible: bool,
     *     total_checked: int,
     *     incompatible_modules: array<int, array{module_id: string, name: string, installed_version: string, reason: string}>,
     *     warnings: array<int, string>
     * }
     */
    public function checkCompatibility(string $targetCoreVersion, array $targetRequiredModules = []): array
    {
        $this->ensureModuleTable();

        $modules = $this->db->select(
            "SELECT module_id, name, version, is_enabled, manifest FROM installed_modules WHERE is_enabled = 1"
        );

        $totalChecked = count($modules);
        $incompatibles = [];
        $warnings = [];

        foreach ($modules as $mod) {
            $moduleId = (string) $mod['module_id'];
            $name = (string) $mod['name'];
            $installedVersion = (string) $mod['version'];
            $rawManifest = (string) ($mod['manifest'] ?? '');

            $manifestArray = json_decode($rawManifest, true);
            if (is_array($manifestArray)) {
                $minCore = (string) ($manifestArray['min_core_version'] ?? '1.0.0');
                $maxCore = (string) ($manifestArray['max_core_version'] ?? '');

                // Check min core version
                if (version_compare($targetCoreVersion, $minCore, '<')) {
                    $incompatibles[] = [
                        'module_id' => $moduleId,
                        'name' => $name,
                        'installed_version' => $installedVersion,
                        'reason' => sprintf('Target core %s is lower than module minimum %s', $targetCoreVersion, $minCore),
                    ];
                    continue;
                }

                // Check max core version if specified
                if ($maxCore !== '' && version_compare($targetCoreVersion, $maxCore, '>')) {
                    $incompatibles[] = [
                        'module_id' => $moduleId,
                        'name' => $name,
                        'installed_version' => $installedVersion,
                        'reason' => sprintf('Target core %s exceeds module maximum tested version %s', $targetCoreVersion, $maxCore),
                    ];
                    continue;
                }
            }

            // Check if core update requires a newer version of this module
            if (isset($targetRequiredModules[$moduleId])) {
                $requiredModVersion = $targetRequiredModules[$moduleId];
                if (version_compare($installedVersion, $requiredModVersion, '<')) {
                    $incompatibles[] = [
                        'module_id' => $moduleId,
                        'name' => $name,
                        'installed_version' => $installedVersion,
                        'reason' => sprintf('Update requires module version >= %s (installed: %s)', $requiredModVersion, $installedVersion),
                    ];
                }
            }
        }

        return [
            'compatible' => empty($incompatibles),
            'total_checked' => $totalChecked,
            'incompatible_modules' => $incompatibles,
            'warnings' => $warnings,
        ];
    }

    private function ensureModuleTable(): void
    {
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS installed_modules (
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
            $autoInc
        );

        $this->db->statement($sql);
    }
}
