<?php

declare(strict_types=1);

namespace Coleza\Domain\Installer\Exceptions;

use RuntimeException;

final class InstallationLockedException extends RuntimeException
{
    public static function alreadyInstalled(): self
    {
        return new self('Application installation is already completed and locked. Re-running the installer is forbidden.');
    }
}
