<?php

declare(strict_types=1);

namespace Coleza\Domain\Installer;

enum InstallationStep: string
{
    case REQUIREMENTS = 'requirements';
    case DATABASE = 'database';
    case ADMIN = 'admin';
    case LOCALIZATION = 'localization';
    case BRAND = 'brand';
    case EMAIL = 'email';
    case CRON = 'cron';
    case COMPLETED = 'completed';
}
