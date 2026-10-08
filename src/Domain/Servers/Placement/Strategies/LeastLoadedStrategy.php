<?php

declare(strict_types=1);

namespace Coleza\Domain\Servers\Placement\Strategies;

use Coleza\Domain\Servers\Entities\Server;
use Coleza\Domain\Servers\Entities\ServerPool;
use Coleza\Domain\Servers\Placement\PlacementRequest;

final class LeastLoadedStrategy implements PlacementStrategyInterface
{
    public function getName(): string
    {
        return ServerPool::STRATEGY_LEAST_LOADED;
    }

    /**
     * Selects the server with the lowest composite load score and lowest account count.
     *
     * @param array<Server> $eligibleServers
     */
    public function selectServer(array $eligibleServers, PlacementRequest $request): ?Server
    {
        if (empty($eligibleServers)) {
            return null;
        }

        $sorted = $eligibleServers;
        usort($sorted, function (Server $a, Server $b) {
            $scoreA = $a->getCapacity()->calculateLoadScore();
            $scoreB = $b->getCapacity()->calculateLoadScore();

            if (abs($scoreA - $scoreB) < 0.0001) {
                return $a->getCapacity()->getUsedAccounts() <=> $b->getCapacity()->getUsedAccounts();
            }

            return $scoreA <=> $scoreB;
        });

        return $sorted[0];
    }
}
