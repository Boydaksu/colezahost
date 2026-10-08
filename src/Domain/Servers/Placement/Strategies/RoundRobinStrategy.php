<?php

declare(strict_types=1);

namespace Coleza\Domain\Servers\Placement\Strategies;

use Coleza\Domain\Servers\Entities\Server;
use Coleza\Domain\Servers\Entities\ServerPool;
use Coleza\Domain\Servers\Placement\PlacementRequest;

final class RoundRobinStrategy implements PlacementStrategyInterface
{
    private static int $counter = 0;

    public function getName(): string
    {
        return ServerPool::STRATEGY_ROUND_ROBIN;
    }

    /**
     * Cycles through eligible servers sequentially.
     *
     * @param array<Server> $eligibleServers
     */
    public function selectServer(array $eligibleServers, PlacementRequest $request): ?Server
    {
        if (empty($eligibleServers)) {
            return null;
        }

        $values = array_values($eligibleServers);
        $count = count($values);
        $index = self::$counter % $count;
        self::$counter++;

        return $values[$index];
    }

    public static function resetCounter(): void
    {
        self::$counter = 0;
    }
}
