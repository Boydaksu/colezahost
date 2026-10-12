<?php

declare(strict_types=1);

namespace Coleza\Domain\Provisioning\Reconciliation;

final class ReconciliationStatus
{
    public const RECONCILED_EXISTS = 'reconciled_exists';
    public const RECONCILED_NOT_FOUND = 'reconciled_not_found';
    public const RECONCILED_UNREACHABLE = 'reconciled_unreachable';
    public const RECONCILIATION_REQUIRED = 'reconciliation_required';

    /**
     * @return array<string>
     */
    public static function all(): array
    {
        return [
            self::RECONCILED_EXISTS,
            self::RECONCILED_NOT_FOUND,
            self::RECONCILED_UNREACHABLE,
            self::RECONCILIATION_REQUIRED,
        ];
    }
}
