<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Safety;

final class BlastRadiusLimiter
{
    /**
     * @var array<int, array{action: string, target_id: ?string, timestamp: int}>
     */
    private array $history = [];

    public function __construct(
        private readonly int $maxDestructiveActions = 5,
        private readonly int $windowSeconds = 3600,
        private readonly ?DestructiveActionRegistry $registry = null,
        private readonly ?EmergencyPauseManagerInterface $emergencyPause = null
    ) {
    }

    public function getMaxDestructiveActions(): int
    {
        return $this->maxDestructiveActions;
    }

    public function getWindowSeconds(): int
    {
        return $this->windowSeconds;
    }

    public function getDestructiveRegistry(): DestructiveActionRegistry
    {
        return $this->registry ?? new DestructiveActionRegistry();
    }

    public function getEmergencyPauseManager(): ?EmergencyPauseManagerInterface
    {
        return $this->emergencyPause;
    }

    /**
     * Check if executing the action is allowed under the current blast radius threshold.
     */
    public function canExecute(string $actionType): bool
    {
        $registry = $this->getDestructiveRegistry();
        if (!$registry->isDestructive($actionType)) {
            return true;
        }

        $recentCount = $this->getRecentDestructiveCount();
        return $recentCount < $this->maxDestructiveActions;
    }

    /**
     * Record execution of an action. If it is destructive and limit is exceeded,
     * trip the emergency pause if configured and return false.
     */
    public function recordAndCheck(string $actionType, ?string $targetId = null): bool
    {
        $registry = $this->getDestructiveRegistry();
        if (!$registry->isDestructive($actionType)) {
            return true;
        }

        $now = time();
        $this->pruneHistory($now);

        $this->history[] = [
            'action' => $actionType,
            'target_id' => $targetId,
            'timestamp' => $now,
        ];

        if (count($this->history) > $this->maxDestructiveActions) {
            if ($this->emergencyPause !== null) {
                $this->emergencyPause->pause(
                    pausedBy: 'BlastRadiusLimiter',
                    reason: "Blast radius threshold exceeded: more than {$this->maxDestructiveActions} destructive actions in {$this->windowSeconds}s",
                    scope: 'DESTRUCTIVE'
                );
            }
            return false;
        }

        return true;
    }

    public function getRecentDestructiveCount(): int
    {
        $this->pruneHistory(time());
        return count($this->history);
    }

    public function reset(): void
    {
        $this->history = [];
    }

    private function pruneHistory(int $now): void
    {
        $cutoff = $now - $this->windowSeconds;
        $this->history = array_values(array_filter(
            $this->history,
            fn (array $item) => $item['timestamp'] >= $cutoff
        ));
    }
}
