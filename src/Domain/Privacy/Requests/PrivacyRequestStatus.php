<?php

declare(strict_types=1);

namespace Coleza\Domain\Privacy\Requests;

enum PrivacyRequestStatus: string
{
    case PENDING_VERIFICATION = 'pending_verification';
    case VERIFIED = 'verified';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';

    public function isPendingVerification(): bool
    {
        return $this === self::PENDING_VERIFICATION;
    }

    public function isVerified(): bool
    {
        return $this === self::VERIFIED;
    }

    public function isCompleted(): bool
    {
        return $this === self::COMPLETED;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::REJECTED, self::CANCELLED], true);
    }
}
