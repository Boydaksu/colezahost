<?php

declare(strict_types=1);

namespace Coleza\Domain\Support\Context;

interface TicketContextCommandHandlerInterface
{
    public function supports(string $action): bool;

    /**
     * @param array<string, mixed> $parameters
     */
    public function handle(string $action, int $ticketId, array $parameters = []): CommandExecutionResult;
}
