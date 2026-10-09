<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Staging;

/**
 * Terminal accounting lifecycle states for migration staging records.
 * Per Data Constitution: Zero Silent Data Loss — every source record gets a terminal accounting state.
 */
enum StagingRecordStatus: string
{
    case STAGED = 'staged';
    case VALIDATED = 'validated';
    case TRANSFORMED = 'transformed';
    case MIGRATED = 'migrated';
    case QUARANTINED = 'quarantined';
    case SKIPPED_UNSUPPORTED = 'skipped_unsupported';
    case FAILED = 'failed';

    /**
     * Checks if this status is terminal.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::MIGRATED,
            self::QUARANTINED,
            self::SKIPPED_UNSUPPORTED,
            self::FAILED => true,
            default => false,
        };
    }
}
