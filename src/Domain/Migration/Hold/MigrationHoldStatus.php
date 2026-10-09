<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Hold;

enum MigrationHoldStatus: string
{
    case ACTIVE = 'ACTIVE';
    case RELEASED = 'RELEASED';
}
