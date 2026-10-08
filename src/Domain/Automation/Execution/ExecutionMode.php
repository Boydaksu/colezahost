<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Execution;

enum ExecutionMode: string
{
    case ACTIVE = 'ACTIVE';
    case OBSERVE = 'OBSERVE';
    case DRY_RUN = 'DRY_RUN';

    public function isLive(): bool
    {
        return $this === self::ACTIVE;
    }

    public function isObserve(): bool
    {
        return $this === self::OBSERVE;
    }

    public function isDryRun(): bool
    {
        return $this === self::DRY_RUN;
    }
}
