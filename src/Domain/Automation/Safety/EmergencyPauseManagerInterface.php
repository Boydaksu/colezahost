<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Safety;

interface EmergencyPauseManagerInterface
{
    /**
     * Pause automations across all operations or within a designated scope.
     */
    public function pause(string $pausedBy, string $reason, string $scope = 'ALL'): void;

    /**
     * Resume automations previously paused.
     */
    public function resume(string $resumedBy, string $reason): void;

    /**
     * Determine if automations are currently paused for a given action type or scope.
     */
    public function isPaused(?string $actionType = null, string $scope = 'ALL'): bool;

    /**
     * Get the current emergency pause state.
     */
    public function getState(): EmergencyPauseState;
}
