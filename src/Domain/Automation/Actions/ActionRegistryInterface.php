<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Actions;

interface ActionRegistryInterface
{
    public function register(ActionHandlerInterface $handler): void;

    public function has(string $type): bool;

    public function get(string $type): ActionHandlerInterface;

    /**
     * @return array<string, ActionHandlerInterface>
     */
    public function all(): array;
}
