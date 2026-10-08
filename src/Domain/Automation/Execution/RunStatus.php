<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Execution;

enum RunStatus: string
{
    case SUCCESS = 'SUCCESS';
    case FAILED = 'FAILED';
    case PENDING_APPROVAL = 'PENDING_APPROVAL';
    case DELAYED = 'DELAYED';
    case OBSERVED = 'OBSERVED';
    case DRY_RUN = 'DRY_RUN';
    case SKIPPED = 'SKIPPED';
    case PAUSED = 'PAUSED';

    public function isCompleted(): bool
    {
        return in_array($this, [self::SUCCESS, self::OBSERVED, self::DRY_RUN, self::SKIPPED, self::PAUSED], true);
    }

    public function isPending(): bool
    {
        return in_array($this, [self::PENDING_APPROVAL, self::DELAYED], true);
    }
}
