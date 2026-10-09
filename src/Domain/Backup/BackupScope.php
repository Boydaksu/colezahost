<?php

declare(strict_types=1);

namespace Coleza\Domain\Backup;

/**
 * Supported backup scopes.
 */
enum BackupScope: string
{
    case FULL = 'full';
    case DATABASE = 'db';
    case FILES = 'files';
}
