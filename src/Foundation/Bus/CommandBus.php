<?php

declare(strict_types=1);

namespace Coleza\Foundation\Bus;

use Closure;
use Coleza\Foundation\Container\Container;
use RuntimeException;

final class CommandBus
{
    /** @var array<string, class-string|callable> */
    private array $handlers = [];

    /** @var array<int, callable> */
    private array $middlewares = [];

    public function __construct(private ?Container $container = null)
    {
    }

    public function register(string $commandClass, string|callable $handler): void
    {
        $this->handlers[$commandClass] = $handler;
    }

    public function pipe(callable $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    public function dispatch(CommandInterface $command): mixed
    {
        $commandClass = get_class($command);

        if (!isset($this->handlers[$commandClass])) {
            throw new RuntimeException(sprintf('No handler registered for command [%s].', $commandClass));
        }

        $handlerDef = $this->handlers[$commandClass];
        $container = $this->container ?? Container::getInstance();

        $destination = function (CommandInterface $cmd) use ($handlerDef, $container): mixed {
            if (is_string($handlerDef)) {
                $handler = $container->get($handlerDef);
                return $handler->handle($cmd);
            }
            return $handlerDef($cmd);
        };

        // Execute middleware pipeline
        $pipeline = array_reduce(
            array_reverse($this->middlewares),
            function (Closure $next, callable $middleware): Closure {
                return function (CommandInterface $cmd) use ($next, $middleware): mixed {
                    return $middleware($cmd, $next);
                };
            },
            $destination
        );

        return $pipeline($command);
    }
}
