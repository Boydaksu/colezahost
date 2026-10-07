<?php

declare(strict_types=1);

namespace Coleza\Domain\Notifications\Classification;

enum NotificationNature: string
{
    case TRANSACTIONAL = 'transactional';
    case MARKETING = 'marketing';

    public function isOptOutPermitted(): bool
    {
        return $this === self::MARKETING;
    }
}
