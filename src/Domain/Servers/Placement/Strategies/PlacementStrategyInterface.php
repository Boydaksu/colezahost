<?php

declare(strict_types=1);

namespace Coleza\Domain\Servers\Placement\Strategies;

use Coleza\Domain\Servers\Entities\Server;
use Coleza\Domain\Servers\Placement\PlacementRequest;

interface PlacementStrategyInterface
{
    public function getName(): string;

    /**
     * @param array<Server> $eligibleServers
     */
    public function selectServer(array $eligibleServers, PlacementRequest $request): ?Server;
}
