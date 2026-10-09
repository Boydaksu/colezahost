<?php

declare(strict_types=1);

namespace Coleza\Domain\OperationalMode;

use RuntimeException;

final class OperationModeRestrictionException extends RuntimeException
{
    public static function writeForbiddenInReadOnly(string $operation): self
    {
        return new self("Write operation [{$operation}] is blocked: System is in READ_ONLY mode.");
    }

    public static function maintenanceActive(string $message): self
    {
        return new self("System is currently unavailable: {$message}");
    }

    public static function customerBlockedInRecovery(): self
    {
        return new self("Customer access is disabled while system is in RECOVERY mode.");
    }
}
