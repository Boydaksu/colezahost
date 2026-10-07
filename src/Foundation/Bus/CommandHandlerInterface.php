<?php

declare(strict_types=1);

namespace Coleza\Foundation\Bus;

/**
 * @template TCommand of CommandInterface
 */
interface CommandHandlerInterface
{
    /**
     * @param TCommand $command
     */
    public function handle(CommandInterface $command): mixed;
}
