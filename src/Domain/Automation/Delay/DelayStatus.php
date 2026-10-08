<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Delay;

enum DelayStatus: string
{
    case SCHEDULED = 'SCHEDULED';
    case DISPATCHED = 'DISPATCHED';
    case CANCELLED = 'CANCELLED';

    public function isScheduled(): bool
    {
        return $this === self::SCHEDULED;
    }
}
