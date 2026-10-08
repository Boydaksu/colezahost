<?php

declare(strict_types=1);

namespace Coleza\Domain\Servers\Placement\Strategies;

use Coleza\Domain\Servers\Entities\Server;
use Coleza\Domain\Servers\Entities\ServerPool;
use Coleza\Domain\Servers\Placement\PlacementRequest;

final class RandomStrategy implements PlacementStrategyInterface
{
    public function getName(): string
    {
        return ServerPool::STRATEGY_RANDOM;
    }

    /**
     * Randomly picks one of the eligible servers.
     *
     * @param array<Server> $eligibleServers
     */
    public function selectServer(array $eligibleServers, PlacementRequest $request): ?Server
    {
        if (empty($eligibleServers)) {
            return null;
        }

        $values = array_values($eligibleServers);
        $idx = random_int(0, count($values) - 1);
        return $values[$idx];
    }
}
