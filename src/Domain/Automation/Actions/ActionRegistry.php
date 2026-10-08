<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Actions;

use InvalidArgumentException;

final class ActionRegistry implements ActionRegistryInterface
{
    /** @var array<string, ActionHandlerInterface> */
    private array $handlers = [];

    public function register(ActionHandlerInterface $handler): void
    {
        $this->handlers[$handler->getType()] = $handler;
    }

    public function has(string $type): bool
    {
        return isset($this->handlers[$type]);
    }

    public function get(string $type): ActionHandlerInterface
    {
        if (!$this->has($type)) {
            throw new InvalidArgumentException("No action handler registered for type '{$type}'");
        }

        return $this->handlers[$type];
    }

    /**
     * @return array<string, ActionHandlerInterface>
     */
    public function all(): array
    {
        return $this->handlers;
    }
}
