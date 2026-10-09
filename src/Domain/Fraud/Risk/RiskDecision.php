<?php

declare(strict_types=1);

namespace Coleza\Domain\Fraud\Risk;

enum RiskDecision: string
{
    case ACCEPT = 'accept';
    case REVIEW = 'review';
    case REJECT = 'reject';

    public function isAccept(): bool
    {
        return $this === self::ACCEPT;
    }

    public function isReview(): bool
    {
        return $this === self::REVIEW;
    }

    public function isReject(): bool
    {
        return $this === self::REJECT;
    }
}
