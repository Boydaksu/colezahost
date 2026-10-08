<?php

declare(strict_types=1);

namespace Coleza\Domain\Servers\Placement\Strategies;

use Coleza\Domain\Servers\Entities\Server;
use Coleza\Domain\Servers\Entities\ServerPool;
use Coleza\Domain\Servers\Placement\PlacementRequest;

final class FillFirstStrategy implements PlacementStrategyInterface
{
    public function getName(): string
    {
        return ServerPool::STRATEGY_FILL_FIRST;
    }

    /**
     * Selects the server that is most full (highest used accounts) to consolidate capacity before spilling over.
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
            $usedA = $a->getCapacity()->getUsedAccounts();
            $usedB = $b->getCapacity()->getUsedAccounts();

            return $usedB <=> $usedA;
        });

        return $sorted[0];
    }
}
