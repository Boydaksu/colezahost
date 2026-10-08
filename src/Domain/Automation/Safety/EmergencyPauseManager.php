<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Safety;

use DateTimeImmutable;

final class EmergencyPauseManager implements EmergencyPauseManagerInterface
{
    private EmergencyPauseState $state;
    private DestructiveActionRegistry $destructiveRegistry;

    public function __construct(?DestructiveActionRegistry $destructiveRegistry = null)
    {
        $this->state = EmergencyPauseState::active();
        $this->destructiveRegistry = $destructiveRegistry ?? new DestructiveActionRegistry();
    }

    public function pause(string $pausedBy, string $reason, string $scope = 'ALL'): void
    {
        $this->state = EmergencyPauseState::paused($pausedBy, $reason, $scope);
    }

    public function resume(string $resumedBy, string $reason): void
    {
        $pausedAt = $this->state->getPausedAt();
        $scope = $this->state->getScope();

        $this->state = new EmergencyPauseState(
            isPaused: false,
            pausedBy: $this->state->getPausedBy(),
            reason: $this->state->getReason(),
            pausedAt: $pausedAt,
            resumedBy: $resumedBy,
            resumedAt: new DateTimeImmutable(),
            scope: $scope
        );
    }

    public function isPaused(?string $actionType = null, string $scope = 'ALL'): bool
    {
        if (!$this->state->isPaused()) {
            return false;
        }

        // Global kill switch pauses everything
        if ($this->state->getScope() === 'ALL') {
            return true;
        }

        // DESTRUCTIVE scope pauses all destructive actions
        if ($this->state->getScope() === 'DESTRUCTIVE') {
            if ($actionType !== null && $this->destructiveRegistry->isDestructive($actionType)) {
                return true;
            }
            return false;
        }

        // Scoped pause (e.g. BILLING, PROVISIONING)
        return strtoupper(trim($scope)) === $this->state->getScope();
    }

    public function getState(): EmergencyPauseState
    {
        return $this->state;
    }
}
