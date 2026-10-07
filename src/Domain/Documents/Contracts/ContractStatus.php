<?php

declare(strict_types=1);

namespace Coleza\Domain\Documents\Contracts;

enum ContractStatus: string
{
    case DRAFT = 'draft';
    case PENDING_ACCEPTANCE = 'pending_acceptance';
    case ACTIVE = 'active';
    case TERMINATED = 'terminated';
    case EXPIRED = 'expired';

    public function isFinal(): bool
    {
        return in_array($this, [self::TERMINATED, self::EXPIRED], true);
    }
}
