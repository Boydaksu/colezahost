<?php

declare(strict_types=1);

namespace Coleza\Domain\Module;

use Coleza\Domain\Identity\Audit\AuditLogger;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Database\PdoSchema;
use Coleza\Foundation\Exceptions\ValidationException;
use Coleza\Domain\Updater\UpdatePackageManifest;

final class ModuleLifecycleService
{
    private string $table = 'installed_modules';

    public function __construct(
        private Connection $db,
        private string $coreVersion = '1.0.0',
        private ?AuditLogger $auditLogger = null
    ) {
    }

    public static function schemaRequirements(): array
    {
        return ['installed_modules' => ['id', 'module_id', 'name', 'version', 'type', 'is_enabled', 'manifest', 'installed_at', 'updated_at']];
    }

    private static function assertCallerTransaction(Connection $db): void
    {
        if ($db->inTransaction() && !$db->getPdo()->inTransaction()) {
            throw new \LogicException('Module operation refused: caller transaction ended unexpectedly.');
        }
    }

    /** Compatibility inspection must not install or repair the inventory it is checking. */
    public static function assertInstalledSchema(Connection $db): void
    {
        self::assertCallerTransaction($db);
        if (!PdoSchema::useInstalledSchema($db, self::schemaRequirements())) {
            throw new \LogicException('Module inventory schema is missing; run installation or explicit upgrade before checking compatibility.');
        }
    }

    public function ensureTable(bool $installSchema = false): void
    {
        self::assertCallerTransaction($this->db);
        if (PdoSchema::useInstalledSchema($this->db, self::schemaRequirements(), $installSchema)) { return; }
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
        ) . ($driver === 'mysql' ? ' ENGINE=InnoDB' : '');

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
            if(file_exists($dir.'/extension.json')){continue;} // Packaged extensions belong to ExtensionInstaller.
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
     * Trusted built-in registration only. Uploaded packages must use ExtensionInstaller.
     */
    public function install(ModuleManifest $manifest, ?int $actorUserId = null): void
    {
        $this->ensureTable();
        $this->assertTransactionalStorage();

        $manifest=ModuleManifest::fromArray($manifest->toArray());
        if (UpdatePackageManifest::compareVersions($this->installedCoreVersion(),$manifest->minCoreVersion)<0
            ||($manifest->maxCoreVersion!==null&&UpdatePackageManifest::compareVersions($this->installedCoreVersion(),$manifest->maxCoreVersion)>0)
            ||$manifest->extensionApiVersion!=='1.0.0') {
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
        $this->assertDependencies($manifest);

        $existing = $this->db->selectOne(
            sprintf('SELECT id FROM %s WHERE module_id = :id', $this->table),
            ['id' => $manifest->id]
        );

        if ($existing !== null) {
            throw new ValidationException(
                ['module' => [sprintf('Module [%s] is already installed.', $manifest->id)]]
            );
        }

        $this->prepareAudit();
        $this->db->transaction(function()use($manifest,$actorUserId):void{
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
        });
    }

    /**
     * Enable installed module.
     */
    public function enable(string $moduleId, ?int $actorUserId = null): void
    {
        $this->ensureTable();
        $this->assertTransactionalStorage();
        $record = $this->db->selectOne(
            sprintf('SELECT id FROM %s WHERE module_id = :id', $this->table),
            ['id' => $moduleId]
        );

        if ($record === null) {
            throw new ValidationException(['module' => ['Module is not installed.']]);
        }

        $row=$this->db->selectOne('SELECT manifest FROM installed_modules WHERE module_id=?',[$moduleId]);
        $data=json_decode($row['manifest'],true,flags:JSON_THROW_ON_ERROR);
        if(array_key_exists('_extension',$data)){throw new \RuntimeException('Packaged extensions must pass installer health and permission admission before enable.');}
        $manifest=ModuleManifest::fromArray($data);
        if($manifest->extensionApiVersion!=='1.0.0'||UpdatePackageManifest::compareVersions($this->installedCoreVersion(),$manifest->minCoreVersion)<0||($manifest->maxCoreVersion!==null&&UpdatePackageManifest::compareVersions($this->installedCoreVersion(),$manifest->maxCoreVersion)>0)){
            throw new ValidationException(['compatibility'=>['Installed module core bounds changed.']]);
        }
        $this->assertDependencies($manifest);
        $this->prepareAudit();
        $this->db->transaction(function()use($record,$moduleId,$actorUserId):void{
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
        });
    }

    /**
     * Disable module (Extension Constitution: disable != uninstall).
     */
    public function disable(string $moduleId, ?int $actorUserId = null): void
    {
        $this->ensureTable();
        $this->assertTransactionalStorage();
        $record = $this->db->selectOne(
            sprintf('SELECT id FROM %s WHERE module_id = :id', $this->table),
            ['id' => $moduleId]
        );

        if ($record === null) {
            throw new ValidationException(['module' => ['Module is not installed.']]);
        }

        $this->prepareAudit();
        $this->db->transaction(function()use($record,$moduleId,$actorUserId):void{
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
        });
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

    private function prepareAudit(): void
    {
        if($this->auditLogger!==null){
            if($this->auditLogger->connection()->getPdo()!==$this->db->getPdo()){throw new \LogicException('Module audit database differs from inventory.');}
            $this->auditLogger->ensureTable();
        }
    }
    private function installedCoreVersion(): string
    {
        if(\Coleza\Domain\Vault\KeyBinding::tableExists($this->db,'system_settings')){
            $row=$this->db->selectOne('SELECT setting_value FROM system_settings WHERE setting_key=?',['system.core_version']);
            if($row!==null){UpdatePackageManifest::assertVersion($row['setting_value']);return $row['setting_value'];}
        }
        return $this->coreVersion;
    }
    private function assertDependencies(ModuleManifest $manifest): void
    {
        foreach($manifest->dependencies as $id=>$version){$row=$this->db->selectOne('SELECT version,is_enabled FROM installed_modules WHERE module_id=?',[$id]);if($row===null||(int)$row['is_enabled']!==1||UpdatePackageManifest::compareVersions($row['version'],$version)<0){throw new ValidationException(['dependencies'=>['Required module is missing, disabled or outdated.']]);}}
    }

    private function assertTransactionalStorage(): void
    {
        if ($this->db->getDriverName() === 'mysql') {
            $table = $this->db->selectOne("SELECT ENGINE FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'installed_modules'");
            if ($table === null || strcasecmp((string) $table['ENGINE'], 'InnoDB') !== 0) {
                throw new \LogicException('Module writes require InnoDB; explicit storage upgrade required.');
            }
        }
    }
}
