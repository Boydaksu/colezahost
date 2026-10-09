<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Execution;

enum ConflictResolutionStrategy: string
{
    case QUARANTINE = 'quarantine';
    case LINK_EXISTING = 'link_existing';
    case FAIL_FAST = 'fail_fast';
}
