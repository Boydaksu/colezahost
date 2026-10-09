<?php

declare(strict_types=1);

namespace Coleza\Domain\Fraud\Review;

enum RiskReviewStatus: string
{
    case PENDING = 'pending';
    case IN_REVIEW = 'in_review';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case ESCALATED = 'escalated';

    public function isPending(): bool
    {
        return $this === self::PENDING;
    }

    public function isInReview(): bool
    {
        return $this === self::IN_REVIEW;
    }

    public function isResolved(): bool
    {
        return in_array($this, [self::APPROVED, self::REJECTED], true);
    }

    public function isEscalated(): bool
    {
        return $this === self::ESCALATED;
    }
}
