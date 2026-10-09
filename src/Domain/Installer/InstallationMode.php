<?php

declare(strict_types=1);

namespace Coleza\Domain\Installer;

/**
 * Installation mode selector:
 * - FRESH: Standard clean installation with blank tenant database.
 * - MIGRATION: Fresh install that directly ingests legacy platform data (WHMCS/etc)
 *              under migration hold before opening production operations.
 */
enum InstallationMode: string
{
    case FRESH = 'fresh';
    case MIGRATION = 'migration';
}
