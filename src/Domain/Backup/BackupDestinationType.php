<?php

declare(strict_types=1);

namespace Coleza\Domain\Backup;

/**
 * Storage destinations for backup archives.
 */
enum BackupDestinationType: string
{
    case LOCAL = 'local';
    case SFTP = 'sftp';
    case S3 = 's3';
}
