<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Foundation\Bus;

use Closure;
use Coleza\Foundation\Bus\CommandBus;
use Coleza\Foundation\Bus\CommandHandlerInterface;
use Coleza\Foundation\Bus\CommandInterface;
use Coleza\Foundation\Container\Container;
use PHPUnit\Framework\TestCase;

class CreateUserCommand implements CommandInterface
{
    public function __construct(public string $username, public string $email) {}
}

class CreateUserHandler implements CommandHandlerInterface
{
    public function handle(CommandInterface $command): string
    {
        /** @var CreateUserCommand $command */
        return 'user_created:' . $command->username;
    }
}

final class CommandBusTest extends TestCase
{
    private Container $container;
    private CommandBus $bus;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->bus = new CommandBus($this->container);
    }

    public function testDispatchesCommandToRegisteredHandler(): void
    {
        $this->bus->register(CreateUserCommand::class, CreateUserHandler::class);

        $result = $this->bus->dispatch(new CreateUserCommand('alice', 'alice@colezahost.com'));
        $this->assertSame('user_created:alice', $result);
    }

    public function testCommandBusMiddlewareExecution(): void
    {
        $middlewareTrace = [];

        $this->bus->pipe(function (CommandInterface $cmd, Closure $next) use (&$middlewareTrace): mixed {
            $middlewareTrace[] = 'before';
            $res = $next($cmd);
            $middlewareTrace[] = 'after';
            return $res;
        });

        $this->bus->register(CreateUserCommand::class, CreateUserHandler::class);
        $result = $this->bus->dispatch(new CreateUserCommand('bob', 'bob@colezahost.com'));

        $this->assertSame('user_created:bob', $result);
        $this->assertSame(['before', 'after'], $middlewareTrace);
    }
}
