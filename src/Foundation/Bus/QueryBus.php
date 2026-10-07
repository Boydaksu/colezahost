<?php

declare(strict_types=1);

namespace Coleza\Foundation\Bus;

use Coleza\Foundation\Container\Container;
use RuntimeException;

final class QueryBus
{
    /** @var array<string, class-string|callable> */
    private array $handlers = [];

    public function __construct(private ?Container $container = null)
    {
    }

    public function register(string $queryClass, string|callable $handler): void
    {
        $this->handlers[$queryClass] = $handler;
    }

    public function ask(QueryInterface $query): mixed
    {
        $queryClass = get_class($query);

        if (!isset($this->handlers[$queryClass])) {
            throw new RuntimeException(sprintf('No handler registered for query [%s].', $queryClass));
        }

        $handlerDef = $this->handlers[$queryClass];
        $container = $this->container ?? Container::getInstance();

        if (is_string($handlerDef)) {
            $handler = $container->get($handlerDef);
            return $handler->handle($query);
        }

        return $handlerDef($query);
    }
}
