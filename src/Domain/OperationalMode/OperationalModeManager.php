<?php

declare(strict_types=1);

namespace Coleza\Domain\OperationalMode;

use Coleza\Foundation\Database\Connection;
use RuntimeException;

/**
 * Service managing operational modes: Normal, Safe, Recovery, Read-Only, and Full Maintenance.
 * Provides:
 * - Atomic mode transitions with audit reasons and initiator tracking.
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
        $this->ensureSettingsTable();
    }

    public function getCurrentMode(): OperationalMode
    {
        // 1. Check local file override if configured
        if ($this->lockFilePath !== null && file_exists($this->lockFilePath)) {
            $raw = trim((string) file_get_contents($this->lockFilePath));
            $mode = OperationalMode::tryFrom($raw);
            if ($mode !== null) {
                return $mode;
            }
        }

        // 2. Query database settings
        $row = $this->db->selectOne(
            "SELECT setting_value FROM system_settings WHERE setting_key = :key",
            ['key' => self::SETTING_KEY_MODE]
        );

        if ($row !== null && !empty($row['setting_value'])) {
            $mode = OperationalMode::tryFrom((string) $row['setting_value']);
            if ($mode !== null) {
                return $mode;
            }
        }

        return OperationalMode::NORMAL;
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
        $mode = $this->getCurrentMode();

        $reasonRow = $this->db->selectOne("SELECT setting_value FROM system_settings WHERE setting_key = :k", ['k' => self::SETTING_KEY_REASON]);
        $timeRow = $this->db->selectOne("SELECT setting_value FROM system_settings WHERE setting_key = :k", ['k' => self::SETTING_KEY_ACTIVATED_AT]);
        $ipRow = $this->db->selectOne("SELECT setting_value FROM system_settings WHERE setting_key = :k", ['k' => self::SETTING_KEY_WHITELIST_IPS]);

        $ips = [];
        if ($ipRow !== null && !empty($ipRow['setting_value'])) {
            $decoded = json_decode((string) $ipRow['setting_value'], true);
            if (is_array($decoded)) {
                $ips = array_values($decoded);
            }
        }

        return [
            'mode' => $mode->value,
            'reason' => (string) ($reasonRow['setting_value'] ?? 'Standard operations'),
            'activated_at' => (string) ($timeRow['setting_value'] ?? date('c')),
            'whitelist_ips' => $ips,
            'allows_customer_access' => $mode->allowsCustomerAccess(),
            'allows_writes' => $mode->allowsWriteOperations(),
            'allows_cron' => $mode->allowsAutomatedJobs(),
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
        $now = date('c');

        $this->upsertSetting(self::SETTING_KEY_MODE, $mode->value);
        $this->upsertSetting(self::SETTING_KEY_REASON, $reason ?: 'Mode changed to ' . $mode->value);
        $this->upsertSetting(self::SETTING_KEY_ACTIVATED_AT, $now);
        $this->upsertSetting(self::SETTING_KEY_WHITELIST_IPS, json_encode(array_values($whitelistIps)));

        if ($this->lockFilePath !== null) {
            if ($mode === OperationalMode::NORMAL) {
                if (file_exists($this->lockFilePath)) {
                    @unlink($this->lockFilePath);
                }
            } else {
                file_put_contents($this->lockFilePath, $mode->value);
            }
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
        $mode = $this->getCurrentMode();

        // Super-admins can bypass maintenance & recovery if not strictly DB-read-only
        if ($isSuperAdmin && $mode !== OperationalMode::READ_ONLY) {
            return;
        }

        // Check IP whitelist during Maintenance/Recovery
        if ($mode->requiresAdminBypass() && $clientIp !== null) {
            $status = $this->getModeStatus();
            if (in_array($clientIp, $status['whitelist_ips'], true)) {
                return;
            }
        }

        // 1. Full Maintenance check
        if ($mode === OperationalMode::FULL_MAINTENANCE) {
            $status = $this->getModeStatus();
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
        $existing = $this->db->selectOne("SELECT id FROM system_settings WHERE setting_key = :k", ['k' => $key]);
        if ($existing !== null) {
            $this->db->statement(
                "UPDATE system_settings SET setting_value = :v, updated_at = CURRENT_TIMESTAMP WHERE setting_key = :k",
                ['v' => $value, 'k' => $key]
            );
        } else {
            $this->db->statement(
                "INSERT INTO system_settings (setting_key, setting_value, created_at) VALUES (:k, :v, CURRENT_TIMESTAMP)",
                ['k' => $key, 'v' => $value]
            );
        }
    }

    private function ensureSettingsTable(): void
    {
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS system_settings (
                id %s,
                setting_key VARCHAR(100) NOT NULL UNIQUE,
                setting_value LONGTEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL
            )',
            $autoInc
        );

        $this->db->statement($sql);
    }
}
