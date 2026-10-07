<?php

declare(strict_types=1);

namespace Coleza\Domain\Notifications\Channel;

enum NotificationPriority: string
{
    case LOW = 'low';
    case NORMAL = 'normal';
    case HIGH = 'high';
    case URGENT = 'urgent';
}
