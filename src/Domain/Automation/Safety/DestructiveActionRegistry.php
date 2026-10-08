<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Safety;

final class DestructiveActionRegistry
{
    public const DEFAULT_DESTRUCTIVE_ACTIONS = [
        'service.terminate' => 10,
        'service.cancel' => 7,
        'service.suspend' => 5,
        'server.destroy' => 10,
        'server.rebuild' => 9,
        'account.purge' => 10,
        'domain.delete' => 8,
        'database.drop' => 10,
    ];

    /**
     * @var array<string, int> Action name => risk severity score (1-10)
     */
    private array $destructiveActions;

    /**
     * @param array<string, int>|null $customActions
     */
    public function __construct(?array $customActions = null)
    {
        $this->destructiveActions = $customActions ?? self::DEFAULT_DESTRUCTIVE_ACTIONS;
    }

    public function registerDestructive(string $actionType, int $riskLevel = 5): void
    {
        $this->destructiveActions[trim($actionType)] = max(1, min(10, $riskLevel));
    }

    public function unregisterDestructive(string $actionType): void
    {
        unset($this->destructiveActions[trim($actionType)]);
    }

    public function isDestructive(string $actionType): bool
    {
        return isset($this->destructiveActions[trim($actionType)]);
    }

    public function getRiskLevel(string $actionType): int
    {
        return $this->destructiveActions[trim($actionType)] ?? 0;
    }

    /**
     * @return array<string, int>
     */
    public function all(): array
    {
        return $this->destructiveActions;
    }
}
