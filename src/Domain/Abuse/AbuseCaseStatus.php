<?php

declare(strict_types=1);

namespace Coleza\Domain\Abuse;

enum AbuseCaseStatus: string
{
    case OPEN = 'open';
    case WAITING_CLIENT_RESPONSE = 'waiting_client_response';
    case CLIENT_RESPONDED = 'client_responded';
    case UNDER_REVIEW = 'under_review';
    case RESOLVED = 'resolved';
    case SUSPENDED = 'suspended';
    case DISMISSED = 'dismissed';

    public function isOpen(): bool
    {
        return !in_array($this, [self::RESOLVED, self::SUSPENDED, self::DISMISSED], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::RESOLVED, self::SUSPENDED, self::DISMISSED], true);
    }
}
