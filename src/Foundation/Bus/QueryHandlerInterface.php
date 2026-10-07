<?php

declare(strict_types=1);

namespace Coleza\Foundation\Bus;

/**
 * @template TQuery of QueryInterface
 */
interface QueryHandlerInterface
{
    /**
     * @param TQuery $query
     */
    public function handle(QueryInterface $query): mixed;
}
