<?php

declare(strict_types=1);

namespace Coleza\Domain\OperationalMode;

/**
 * System operational lifecycle modes:
 * - NORMAL: Default full read-write operations for staff and customers.
 * - SAFE: Disables non-essential background jobs and experimental/third-party hooks.
 * - RECOVERY: Minimal emergency mode for disaster recovery; staff only.
 * - READ_ONLY: Read operations permitted for clients & staff; mutations rejected.
 * - FULL_MAINTENANCE: Full maintenance window; only super-admin whitelist allowed.
 */
enum OperationalMode: string
{
    case NORMAL = 'normal';
    case SAFE = 'safe';
    case RECOVERY = 'recovery';
    case READ_ONLY = 'read_only';
    case FULL_MAINTENANCE = 'full_maintenance';

    public function allowsCustomerAccess(): bool
    {
        return match ($this) {
            self::NORMAL, self::SAFE, self::READ_ONLY => true,
            self::RECOVERY, self::FULL_MAINTENANCE => false,
        };
    }

    public function allowsWriteOperations(): bool
    {
        return match ($this) {
            self::NORMAL, self::SAFE => true,
            self::READ_ONLY, self::RECOVERY, self::FULL_MAINTENANCE => false,
        };
    }

    public function allowsAutomatedJobs(): bool
    {
        return match ($this) {
            self::NORMAL => true,
            self::SAFE => false, // only essential heartbeats
            self::READ_ONLY, self::RECOVERY, self::FULL_MAINTENANCE => false,
        };
    }

    public function requiresAdminBypass(): bool
    {
        return match ($this) {
            self::RECOVERY, self::FULL_MAINTENANCE => true,
            default => false,
        };
    }
}
