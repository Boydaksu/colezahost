<?php

declare(strict_types=1);

namespace Coleza\Domain\Documents\Contracts;

use Coleza\Domain\Documents\Quotes\IllegalStateTransitionException;

final class ContractStateMachine
{
    /**
     * @var array<string, array<string>>
     */
    private const ALLOWED_TRANSITIONS = [
        ContractStatus::DRAFT->value => [
            ContractStatus::PENDING_ACCEPTANCE->value,
            ContractStatus::TERMINATED->value,
        ],
        ContractStatus::PENDING_ACCEPTANCE->value => [
            ContractStatus::ACTIVE->value,
            ContractStatus::TERMINATED->value,
        ],
        ContractStatus::ACTIVE->value => [
            ContractStatus::TERMINATED->value,
            ContractStatus::EXPIRED->value,
        ],
        ContractStatus::TERMINATED->value => [],
        ContractStatus::EXPIRED->value => [],
    ];

    public function canTransition(ContractStatus $from, ContractStatus $to): bool
    {
        $allowed = self::ALLOWED_TRANSITIONS[$from->value] ?? [];
        return in_array($to->value, $allowed, true);
    }

    public function transition(ContractStatus $from, ContractStatus $to): ContractStatus
    {
        if (!$this->canTransition($from, $to)) {
            throw new IllegalStateTransitionException(
                "Cannot transition contract from state '{$from->value}' to '{$to->value}'."
            );
        }

        return $to;
    }
}
