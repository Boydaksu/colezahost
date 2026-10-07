<?php

declare(strict_types=1);

namespace Coleza\Domain\Notifications\Channel;

enum NotificationChannel: string
{
    case EMAIL = 'email';
    case IN_APP = 'in_app';
    case SMS = 'sms';
    case WEBHOOK = 'webhook';
}
