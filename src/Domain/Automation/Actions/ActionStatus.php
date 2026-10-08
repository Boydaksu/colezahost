<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Actions;

enum ActionStatus: string
{
    case SUCCESS = 'SUCCESS';
    case FAILED = 'FAILED';
    case SKIPPED = 'SKIPPED';
}
