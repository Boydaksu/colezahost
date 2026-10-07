<?php

declare(strict_types=1);

namespace Coleza\Domain\Documents\Quotes;

enum QuoteStatus: string
{
    case DRAFT = 'draft';
    case SENT = 'sent';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
    case EXPIRED = 'expired';
    case CONVERTED = 'converted';

    public function isFinal(): bool
    {
        return in_array($this, [self::REJECTED, self::EXPIRED, self::CONVERTED], true);
    }
}
