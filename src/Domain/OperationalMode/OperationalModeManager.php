<?php

declare(strict_types=1);

namespace Coleza\Domain\OperationalMode;

use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Database\PdoSchema;
use RuntimeException;

/**
 * Service managing operational modes: Normal, Safe, Recovery, Read-Only, and Full Maintenance.
 * Provides:
 * - Atomic persisted mode/reason/time/whitelist transitions. Actor auditing is separate.
 * - Persistent configuration in system_settings and fast local lock file cache.
 * - IP and Super-Admin whitelisting during maintenance windows.
 * - Mutation/write guard enforcement.
 */
final class OperationalModeManager
{
    private const SETTING_KEY_MODE = 'system.operational_mode';
    private const SETTING_KEY_REASON = 'system.operational_mode_reason';
    private const SETTING_KEY_ACTIVATED_AT = 'system.operational_mode_activated_at';
    private const SETTING_KEY_WHITELIST_IPS = 'system.maintenance_whitelist_ips';

    public function __construct(
        private Connection $db,
        private ?string $lockFilePath = null
    ) {
        $this->assertCaller();
        self::ensureSchema($this->db);
    }

    public function getCurrentMode(): OperationalMode
    {
        return $this->readState()['mode'];
    }

    /**
     * @return array{
     *     mode: string,
     *     reason: string,
     *     activated_at: string,
     *     whitelist_ips: array<int, string>,
     *     allows_customer_access: bool,
     *     allows_writes: bool,
     *     allows_cron: bool
     * }
     */
    public function getModeStatus(): array
    {
        $state = $this->readState(); $mode = $state['mode'];
        return [
            'mode' => $mode->value, 'reason' => $state['reason'], 'activated_at' => $state['activated_at'],
            'whitelist_ips' => $state['whitelist_ips'], 'allows_customer_access' => $mode->allowsCustomerAccess(),
            'allows_writes' => $mode->allowsWriteOperations(), 'allows_cron' => $mode->allowsAutomatedJobs(),
        ];
    }

    /**
     * Transitions system to a target operational mode.
     *
     * @param OperationalMode $mode
     * @param string $reason Audit rationale
     * @param array<int, string> $whitelistIps Allowed IP addresses during maintenance/recovery
     */
    public function transitionTo(
        OperationalMode $mode,
        string $reason = '',
        array $whitelistIps = []
    ): void {
        $this->assertCaller();
        if ($this->lockFilePath !== null && $this->db->getPdo()->inTransaction()) {
            throw new \LogicException('A file-backed mode transition requires its own transaction; caller commit is outside this service.');
        }
        foreach ($whitelistIps as $ip) {
            if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) { throw new \InvalidArgumentException('Maintenance whitelist requires valid IP addresses.'); }
        }
        $ipsJson = json_encode(array_values($whitelistIps), JSON_THROW_ON_ERROR);
        if ($this->lockFilePath !== null && (str_contains($this->lockFilePath, '://') || is_dir($this->lockFilePath) || is_link($this->lockFilePath) || !is_writable(dirname($this->lockFilePath)))) {
            throw new RuntimeException('Operational mode cache path is unavailable.');
        }
        $this->write(function () use ($mode, $reason, $ipsJson): void {
            $this->readState(checkFile: false, current: true);
            $this->upsertSetting(self::SETTING_KEY_MODE, $mode->value);
            $this->upsertSetting(self::SETTING_KEY_REASON, $reason ?: 'Mode changed to ' . $mode->value);
            $this->upsertSetting(self::SETTING_KEY_ACTIVATED_AT, date('c'));
            $this->upsertSetting(self::SETTING_KEY_WHITELIST_IPS, $ipsJson);
            if ($this->lockFilePath !== null && $mode !== OperationalMode::NORMAL) { $this->publishFile($mode); }
        });
        // Do not remove a restrictive file before the DB commit. Absence uses the persisted DB mode.
        if ($this->lockFilePath !== null && $mode === OperationalMode::NORMAL && file_exists($this->lockFilePath) && !unlink($this->lockFilePath)) {
            throw new RuntimeException('Mode committed but cache cleanup failed; explicit reconciliation required.');
        }
    }

    /**
     * Asserts whether a request or action is permitted under current operational mode.
     *
     * @param bool $isWriteOperation Whether the action attempts to mutate state
     * @param bool $isCustomerContext Whether the action originates from client area/customer
     * @param ?string $clientIp Remote IP address of request
     * @param bool $isSuperAdmin Whether authenticated actor is super-administrator
     */
    public function assertActionPermitted(
        bool $isWriteOperation = false,
        bool $isCustomerContext = true,
        ?string $clientIp = null,
        bool $isSuperAdmin = false
    ): void {
        $status = $this->getModeStatus();
        $mode = OperationalMode::from($status['mode']);

        // Super-admins can bypass maintenance & recovery if not strictly DB-read-only
        if ($isSuperAdmin && $mode !== OperationalMode::READ_ONLY) {
            return;
        }

        // Check IP whitelist during Maintenance/Recovery
        if ($mode->requiresAdminBypass() && $clientIp !== null) {
            if (in_array($clientIp, $status['whitelist_ips'], true)) {
                return;
            }
        }

        // 1. Full Maintenance check
        if ($mode === OperationalMode::FULL_MAINTENANCE) {
            throw OperationModeRestrictionException::maintenanceActive($status['reason']);
        }

        // 2. Recovery Mode check
        if ($mode === OperationalMode::RECOVERY && $isCustomerContext) {
            throw OperationModeRestrictionException::customerBlockedInRecovery();
        }

        // 3. Read-Only Mode check
        if ($mode === OperationalMode::READ_ONLY && $isWriteOperation) {
            throw OperationModeRestrictionException::writeForbiddenInReadOnly('Data modification');
        }
    }

    private function upsertSetting(string $key, string $value): void
    {
        $conflict = $this->db->getDriverName() === 'mysql'
            ? ' ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP'
            : ' ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = CURRENT_TIMESTAMP';
        $this->db->statement('INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)' . $conflict, [$key, $value]);
    }

    public static function schemaRequirements(): array
    {
        // Shared columns of the installer schema and the older mode-only schema.
        return ['system_settings' => ['id', 'setting_key', 'setting_value', 'updated_at'], 'operational_mode_write_lock' => ['id']];
    }

    public static function ensureSchema(Connection $db, bool $installSchema = false): void
    {
        if (PdoSchema::useInstalledSchema($db, self::schemaRequirements(), $installSchema)) { if ($installSchema) { self::validateStorage($db); } return; }
        if (PdoSchema::useInstalledSchema($db, ['system_settings' => self::schemaRequirements()['system_settings']], $installSchema)) { self::validateStorage($db, validateLock: false); }
        $autoInc = PdoSchema::autoIncrement($db->getPdo());
        $db->statement("CREATE TABLE IF NOT EXISTS system_settings (
            id {$autoInc}, setting_key VARCHAR(100) NOT NULL UNIQUE, setting_value TEXT NULL,
            is_encrypted INT NOT NULL DEFAULT 0, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )" . ($db->getDriverName() === 'mysql' ? ' ENGINE=InnoDB' : ''));
        $db->statement('CREATE TABLE IF NOT EXISTS operational_mode_write_lock (id INT NOT NULL PRIMARY KEY CHECK (id = 1))' . ($db->getDriverName() === 'mysql' ? ' ENGINE=InnoDB' : ''));
        if ($installSchema) { self::validateStorage($db); }
    }

    private function assertCaller(): void
    {
        if (!$this->db->getPdo()->inTransaction() && $this->db->inTransaction()) { throw new \LogicException('Caller transaction ended unexpectedly; operational mode refused.'); }
    }

    private static function validateStorage(Connection $db, bool $validateLock = true): void
    {
        $driver = $db->getDriverName();
        if ($driver === 'mysql') {
            $engine = $db->selectOne("SELECT ENGINE FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'system_settings'");
            if ($engine === null || strcasecmp((string) $engine['ENGINE'], 'InnoDB') !== 0) { throw new \LogicException('Operational mode writes require InnoDB.'); }
            $lockValid = true;
            if ($validateLock) {
                $lockEngine = $db->selectOne("SELECT ENGINE FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'operational_mode_write_lock'");
                if ($lockEngine === null || strcasecmp((string) $lockEngine['ENGINE'], 'InnoDB') !== 0) { throw new \LogicException('Operational mode lock requires InnoDB.'); }
                $lockPk = array_values(array_filter($db->select('SHOW INDEX FROM operational_mode_write_lock'), fn($row) => $row['Key_name'] === 'PRIMARY'));
                $lockValid = count($lockPk) === 1 && $lockPk[0]['Column_name'] === 'id' && (int) $lockPk[0]['Non_unique'] === 0;
            }
            $indexes = [];
            foreach ($db->select('SHOW INDEX FROM system_settings') as $row) {
                if ((int) $row['Non_unique'] === 0 && $row['Sub_part'] === null) { $indexes[$row['Key_name']][] = $row['Column_name']; }
            }
            $valid = isset($indexes['PRIMARY']) && $indexes['PRIMARY'] === ['id'] && in_array(['setting_key'], $indexes, true);
        } else {
            $pk = array_values(array_filter($db->select('PRAGMA table_info(system_settings)'), fn($row) => (int) $row['pk'] > 0));
            $valid = count($pk) === 1 && $pk[0]['name'] === 'id'; $unique = false;
            $lockValid = true;
            if ($validateLock) {
                $lockPk = array_values(array_filter($db->select('PRAGMA table_info(operational_mode_write_lock)'), fn($row) => (int) $row['pk'] > 0));
                $lockValid = count($lockPk) === 1 && $lockPk[0]['name'] === 'id';
            }
            foreach ($db->select('PRAGMA index_list(system_settings)') as $index) {
                if ((int) $index['unique'] === 1 && (int) $index['partial'] === 0) {
                    $name = str_replace("'", "''", $index['name']);
                    if (array_column($db->select("PRAGMA index_info('{$name}')"), 'name') === ['setting_key']) { $unique = true; }
                }
            }
            $valid = $valid && $unique;
        }
        if (!$valid || !$lockValid) { throw new \LogicException('Operational mode requires id PK and a single-column unique setting key; explicit upgrade required.'); }
    }

    private function write(callable $callback): void
    {
        $this->assertCaller(); self::validateStorage($this->db);
        $locked = function () use ($callback): void {
            $driver = $this->db->getDriverName();
            $conflict = $driver === 'mysql' ? ' ON DUPLICATE KEY UPDATE id = id' : ' ON CONFLICT(id) DO NOTHING';
            $this->db->statement('INSERT INTO operational_mode_write_lock (id) VALUES (1)' . $conflict);
            $this->db->lockRow('operational_mode_write_lock', 1); $callback();
        };
        if (!$this->db->getPdo()->inTransaction()) { $this->db->transaction($locked); return; }
        $savepoint = 'mode_write_' . bin2hex(random_bytes(8)); $this->db->statement("SAVEPOINT {$savepoint}");
        try { $locked(); $this->db->statement("RELEASE SAVEPOINT {$savepoint}"); }
        catch (\Throwable $error) {
            if ($this->db->getPdo()->inTransaction()) { $this->db->statement("ROLLBACK TO SAVEPOINT {$savepoint}"); $this->db->statement("RELEASE SAVEPOINT {$savepoint}"); }
            throw $error;
        }
    }

    private function readState(bool $checkFile = true, bool $current = false): array
    {
        $keys = [self::SETTING_KEY_MODE, self::SETTING_KEY_REASON, self::SETTING_KEY_ACTIVATED_AT, self::SETTING_KEY_WHITELIST_IPS];
        $rows = $this->db->select('SELECT * FROM system_settings WHERE setting_key IN (?, ?, ?, ?)' . ($current ? $this->db->forUpdate() : ''), $keys);
        $values = [];
        foreach ($rows as $row) {
            if (!in_array($row['setting_key'], $keys, true) || isset($values[$row['setting_key']]) || (int) ($row['is_encrypted'] ?? 0) !== 0 || !is_string($row['setting_value'])) { throw new \LogicException('Operational mode records are inconsistent; explicit reconciliation required.'); }
            $values[$row['setting_key']] = $row['setting_value'];
        }
        if ($rows === []) { $state = ['mode' => OperationalMode::NORMAL, 'reason' => 'Standard operations', 'activated_at' => date('c'), 'whitelist_ips' => []]; }
        else {
            if (count($values) !== 4 || ($mode = OperationalMode::tryFrom($values[self::SETTING_KEY_MODE] ?? '')) === null) { throw new \LogicException('Operational mode is unknown or partial; explicit reconciliation required.'); }
            try { $ips = json_decode($values[self::SETTING_KEY_WHITELIST_IPS], true, flags: JSON_THROW_ON_ERROR); }
            catch (\JsonException $error) { throw new \LogicException('Operational mode whitelist is invalid.', 0, $error); }
            if (!is_array($ips) || !array_is_list($ips)) { throw new \LogicException('Operational mode whitelist is invalid.'); }
            foreach ($ips as $ip) { if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) { throw new \LogicException('Operational mode whitelist is invalid.'); } }
            $state = ['mode' => $mode, 'reason' => $values[self::SETTING_KEY_REASON], 'activated_at' => $values[self::SETTING_KEY_ACTIVATED_AT], 'whitelist_ips' => $ips];
        }
        if ($checkFile && $this->lockFilePath !== null && file_exists($this->lockFilePath)) {
            if (!is_file($this->lockFilePath) || is_link($this->lockFilePath)) { throw new \LogicException('Operational mode cache is unavailable.'); }
            $raw = file_get_contents($this->lockFilePath);
            $cached = $raw === false ? null : OperationalMode::tryFrom(trim($raw));
            if ($cached === null || ($rows !== [] && $cached !== $state['mode'])) { throw new \LogicException('Operational mode cache does not match persisted state; explicit reconciliation required.'); }
            $state['mode'] = $cached;
        }
        return $state;
    }

    private function publishFile(OperationalMode $mode): void
    {
        $temporary = $this->lockFilePath . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = @fopen($temporary, 'x+b');
        if ($handle === false) { throw new RuntimeException('Operational mode cache staging failed.'); }
        try {
            if (fwrite($handle, $mode->value) !== strlen($mode->value) || !fflush($handle)) { throw new RuntimeException('Operational mode cache write failed.'); }
            fclose($handle); $handle = null;
            if (!@rename($temporary, $this->lockFilePath)) { throw new RuntimeException('Operational mode cache publication failed.'); }
        } finally { if (is_resource($handle)) { fclose($handle); } if (is_file($temporary)) { unlink($temporary); } }
    }
}
