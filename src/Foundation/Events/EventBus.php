<?php

declare(strict_types=1);

namespace Coleza\Foundation\Events;

use Coleza\Foundation\Container\Container;

final class EventBus
{
    /** @var array<string, array<int, class-string|callable>> */
    private array $listeners = [];

    public function __construct(private ?Container $container = null)
    {
    }

    public function listen(string $eventClass, string|callable $listener): void
    {
        $this->listeners[$eventClass][] = $listener;
    }

    public function dispatch(EventInterface $event): void
    {
        $eventClass = get_class($event);
        $listeners = $this->listeners[$eventClass] ?? [];

        if (count($listeners) === 0) {
            return;
        }

        $container = $this->container ?? Container::getInstance();

        foreach ($listeners as $listenerDef) {
            if (is_string($listenerDef)) {
                $listener = $container->get($listenerDef);
                $listener->handle($event);
            } else {
                $listenerDef($event);
            }
        }
    }

    public function hasListeners(string $eventClass): bool
    {
        return !empty($this->listeners[$eventClass]);
    }

    /**
     * @return array<int, class-string|callable>
     */
    public function getListeners(string $eventClass): array
    {
        return $this->listeners[$eventClass] ?? [];
    }
}
