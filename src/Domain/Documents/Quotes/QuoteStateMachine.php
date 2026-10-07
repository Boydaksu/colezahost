<?php

declare(strict_types=1);

namespace Coleza\Domain\Documents\Quotes;

// local exception

final class QuoteStateMachine
{
    /**
     * @var array<string, array<string>>
     */
    private const ALLOWED_TRANSITIONS = [
        QuoteStatus::DRAFT->value => [
            QuoteStatus::SENT->value,
            QuoteStatus::EXPIRED->value,
        ],
        QuoteStatus::SENT->value => [
            QuoteStatus::ACCEPTED->value,
            QuoteStatus::REJECTED->value,
            QuoteStatus::EXPIRED->value,
        ],
        QuoteStatus::ACCEPTED->value => [
            QuoteStatus::CONVERTED->value,
        ],
        QuoteStatus::REJECTED->value => [],
        QuoteStatus::EXPIRED->value => [],
        QuoteStatus::CONVERTED->value => [],
    ];

    public function canTransition(QuoteStatus $from, QuoteStatus $to): bool
    {
        $allowed = self::ALLOWED_TRANSITIONS[$from->value] ?? [];
        return in_array($to->value, $allowed, true);
    }

    public function transition(QuoteStatus $from, QuoteStatus $to): QuoteStatus
    {
        if (!$this->canTransition($from, $to)) {
            throw new IllegalStateTransitionException(
                "Cannot transition quote from state '{$from->value}' to '{$to->value}'."
            );
        }

        return $to;
    }
}
