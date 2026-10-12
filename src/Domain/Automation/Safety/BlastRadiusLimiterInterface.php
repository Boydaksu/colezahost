<?php
declare(strict_types=1);
namespace Coleza\Domain\Automation\Safety;

interface BlastRadiusLimiterInterface
{
    /** Reserve before invoking an external handler; failed/uncertain outcomes consume the permit. */
    public function tryAcquire(string $actionType, ?string $targetId = null): bool;
    /** Advisory only; callers must use tryAcquire for admission. */
    public function canExecute(string $actionType): bool;
    public function getRecentDestructiveCount(): int;
    public function getMaxDestructiveActions(): int;
    public function getWindowSeconds(): int;
    public function getDestructiveRegistry(): DestructiveActionRegistry;
    public function getEmergencyPauseManager(): ?EmergencyPauseManagerInterface;
}
