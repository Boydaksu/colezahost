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

    public function assertConnection(Connection $db): void
    {
        if ($db->getPdo() !== $this->db->getPdo()) {throw new \RuntimeException('Module inventory database differs from updater database.');}
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
        UpdatePackageManifest::assertVersion($targetCoreVersion);
        ModuleLifecycleService::assertInstalledSchema($this->db);

        $modules = $this->db->select(
            "SELECT module_id, name, version, is_enabled, manifest FROM installed_modules WHERE is_enabled = 1"
        );

        $totalChecked = count($modules);
        $incompatibles = [];
        $warnings = [];

        foreach ($targetRequiredModules as $id=>$requiredVersion) {if(!is_string($id)||!is_string($requiredVersion)){throw new \RuntimeException('Invalid required module constraint.');}UpdatePackageManifest::assertVersion($requiredVersion);}
        $enabled = [];
        foreach ($modules as $mod) {
            $moduleId = (string) $mod['module_id'];
            $enabled[$moduleId] = true;
            $name = (string) $mod['name'];
            $installedVersion = (string) $mod['version'];
            $rawManifest = (string) ($mod['manifest'] ?? '');

            $manifestArray = json_decode($rawManifest, true);
            if (!is_array($manifestArray) || $manifestArray === []) {
                $incompatibles[] = ['module_id'=>$moduleId,'name'=>$name,'installed_version'=>$installedVersion,'reason'=>'Installed module manifest is invalid.'];
                continue;
            }
            try {
                UpdatePackageManifest::assertVersion($installedVersion);
                if(isset($manifestArray['min_core_version'],$manifestArray['max_core_version'])&&$manifestArray['max_core_version']!==''&&is_string($manifestArray['min_core_version'])&&is_string($manifestArray['max_core_version'])&&UpdatePackageManifest::compareVersions($manifestArray['min_core_version'],$manifestArray['max_core_version'])>0){throw new \RuntimeException('Inverted module version bounds.');}
                foreach (['min_core_version','max_core_version'] as $bound) {
                    if (array_key_exists($bound,$manifestArray) && $manifestArray[$bound] !== '') {
                        if (!is_string($manifestArray[$bound])) {throw new \RuntimeException('Invalid module version bound.');}
                        UpdatePackageManifest::assertVersion($manifestArray[$bound]);
                    }
                }
            } catch (\RuntimeException) {
                $incompatibles[] = ['module_id'=>$moduleId,'name'=>$name,'installed_version'=>$installedVersion,'reason'=>'Installed module version bounds are invalid.'];
                continue;
            }
            if (is_array($manifestArray)) {
                $minCore = (string) ($manifestArray['min_core_version'] ?? '1.0.0');
                $maxCore = (string) ($manifestArray['max_core_version'] ?? '');

                // Check min core version
                if (UpdatePackageManifest::compareVersions($targetCoreVersion, $minCore)<0) {
                    $incompatibles[] = [
                        'module_id' => $moduleId,
                        'name' => $name,
                        'installed_version' => $installedVersion,
                        'reason' => sprintf('Target core %s is lower than module minimum %s', $targetCoreVersion, $minCore),
                    ];
                    continue;
                }

                // Check max core version if specified
                if ($maxCore !== '' && UpdatePackageManifest::compareVersions($targetCoreVersion, $maxCore)>0) {
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
                if (UpdatePackageManifest::compareVersions($installedVersion, $requiredModVersion)<0) {
                    $incompatibles[] = [
                        'module_id' => $moduleId,
                        'name' => $name,
                        'installed_version' => $installedVersion,
                        'reason' => sprintf('Update requires module version >= %s (installed: %s)', $requiredModVersion, $installedVersion),
                    ];
                }
            }
        }

        foreach ($targetRequiredModules as $id=>$requiredVersion) {
            if (!is_string($id) || !is_string($requiredVersion)) {throw new \RuntimeException('Invalid required module constraint.');}
            UpdatePackageManifest::assertVersion($requiredVersion);
            if (!isset($enabled[$id])) {
                $incompatibles[] = ['module_id'=>$id,'name'=>$id,'installed_version'=>'','reason'=>'Required module is missing or disabled.'];
            }
        }
        return [
            'compatible' => empty($incompatibles),
            'total_checked' => $totalChecked,
            'incompatible_modules' => $incompatibles,
            'warnings' => $warnings,
        ];
    }

}
