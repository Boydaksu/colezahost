<?php

declare(strict_types=1);

namespace Coleza\Domain\Servers\Placement;

use Coleza\Domain\Servers\Entities\Location;
use Coleza\Domain\Servers\Entities\Server;
use Coleza\Domain\Servers\Entities\ServerPool;
use Coleza\Domain\Servers\Placement\Strategies\FillFirstStrategy;
use Coleza\Domain\Servers\Placement\Strategies\LeastLoadedStrategy;
use Coleza\Domain\Servers\Placement\Strategies\PlacementStrategyInterface;
use Coleza\Domain\Servers\Placement\Strategies\RandomStrategy;
use Coleza\Domain\Servers\Placement\Strategies\RoundRobinStrategy;
use Coleza\Domain\Servers\Services\ServerService;

final class PlacementEngine
{
    /** @var array<string, PlacementStrategyInterface> */
    private array $strategies = [];

    /**
     * @param array<PlacementStrategyInterface> $customStrategies
     */
    public function __construct(
        private ServerService $serverService,
        private ?ServerHealthCheckerInterface $healthChecker = null,
        array $customStrategies = []
    ) {
        if ($this->healthChecker === null) {
            $this->healthChecker = new DefaultServerHealthChecker();
        }

        // Register default strategies
        $this->registerStrategy(new LeastLoadedStrategy());
        $this->registerStrategy(new FillFirstStrategy());
        $this->registerStrategy(new RoundRobinStrategy());
        $this->registerStrategy(new RandomStrategy());

        foreach ($customStrategies as $strategy) {
            $this->registerStrategy($strategy);
        }
    }

    public function registerStrategy(PlacementStrategyInterface $strategy): void
    {
        $this->strategies[$strategy->getName()] = $strategy;
    }

    public function getStrategy(string $name): PlacementStrategyInterface
    {
        return $this->strategies[$name] ?? $this->strategies[ServerPool::STRATEGY_LEAST_LOADED];
    }

    /**
     * Evaluate placement request and select the optimal server.
     */
    public function selectServer(PlacementRequest $request): PlacementDecision
    {
        $pool = null;
        $strategyName = ServerPool::STRATEGY_LEAST_LOADED;

        // 1. Resolve candidates from pool or criteria
        if ($request->getServerPoolId() !== null) {
            $pool = $this->serverService->findPoolById($request->getServerPoolId());
            if ($pool === null) {
                return PlacementDecision::rejected("Server pool {$request->getServerPoolId()} not found.");
            }
            if (!$pool->isActive()) {
                return PlacementDecision::rejected("Server pool '{$pool->getName()}' is currently inactive.");
            }

            $strategyName = $pool->getStrategy();
            $candidates = $this->serverService->listServers(poolId: $pool->getId());
        } else {
            $candidates = $this->serverService->listServers(locationId: $request->getLocationId());
            if ($request->getProviderSlug() !== null) {
                $candidates = array_filter(
                    $candidates,
                    fn (Server $s) => $s->getProviderSlug() === $request->getProviderSlug()
                );
            }
        }

        if (empty($candidates)) {
            return PlacementDecision::rejected(
                message: 'No candidate servers found matching pool or provider criteria.',
                rejectionReasons: [],
                candidatesEvaluated: 0
            );
        }

        // 2. Evaluate and filter candidates
        /** @var array<Server> $eligibleServers */
        $eligibleServers = [];
        /** @var array<int, string> $rejectionReasons */
        $rejectionReasons = [];

        foreach ($candidates as $server) {
            $serverId = $server->getId() ?? 0;

            // Check server active state
            if (!$server->isActive()) {
                $rejectionReasons[$serverId] = "Server status is '{$server->getStatus()}' (not active).";
                continue;
            }

            // Health check
            $health = $this->healthChecker->checkHealth($server);
            if (!$health->isHealthy()) {
                $rejectionReasons[$serverId] = "Health check failed: {$health->getMessage()}";
                continue;
            }

            // Account headroom
            if (!$server->getCapacity()->hasAccountHeadroom(1)) {
                $rejectionReasons[$serverId] = "Account limit reached ({$server->getCapacity()->getUsedAccounts()}/{$server->getCapacity()->getMaxAccounts()}).";
                continue;
            }

            // Disk headroom
            if (!$server->getCapacity()->hasDiskHeadroom($request->getRequiredDiskMb())) {
                $rejectionReasons[$serverId] = "Insufficient disk capacity (requested {$request->getRequiredDiskMb()} MB).";
                continue;
            }

            // Bandwidth headroom
            if (!$server->getCapacity()->hasBandwidthHeadroom($request->getRequiredBandwidthMb())) {
                $rejectionReasons[$serverId] = "Insufficient bandwidth capacity (requested {$request->getRequiredBandwidthMb()} MB).";
                continue;
            }

            // Dedicated IP requirement
            if ($request->requiresDedicatedIp() && empty($server->getAssignedIpPool())) {
                $rejectionReasons[$serverId] = 'Server has no available dedicated IP pool.';
                continue;
            }

            // Server is eligible
            $eligibleServers[] = $server;
        }

        if (empty($eligibleServers)) {
            return PlacementDecision::rejected(
                message: 'All candidate servers failed health, status, or capacity checks.',
                rejectionReasons: $rejectionReasons,
                candidatesEvaluated: count($candidates)
            );
        }

        // 3. Preferred server priority check
        if ($request->getPreferredServerId() !== null) {
            foreach ($eligibleServers as $eligible) {
                if ($eligible->getId() === $request->getPreferredServerId()) {
                    $location = $this->resolveLocation($eligible, $pool);
                    return PlacementDecision::selected(
                        server: $eligible,
                        pool: $pool,
                        location: $location,
                        strategy: 'preferred_priority',
                        candidatesEvaluated: count($candidates),
                        rejectionReasons: $rejectionReasons
                    );
                }
            }
        }

        // 4. Execute resolved strategy
        $strategy = $this->getStrategy($strategyName);
        $selected = $strategy->selectServer($eligibleServers, $request);

        if ($selected === null) {
            return PlacementDecision::rejected(
                message: "Strategy '{$strategyName}' was unable to select a server from eligible candidates.",
                rejectionReasons: $rejectionReasons,
                candidatesEvaluated: count($candidates)
            );
        }

        $location = $this->resolveLocation($selected, $pool);

        return PlacementDecision::selected(
            server: $selected,
            pool: $pool,
            location: $location,
            strategy: $strategyName,
            candidatesEvaluated: count($candidates),
            rejectionReasons: $rejectionReasons
        );
    }

    private function resolveLocation(Server $server, ?ServerPool $pool): ?Location
    {
        $locationId = $server->getLocationId() ?? $pool?->getLocationId();
        if ($locationId !== null) {
            return $this->serverService->findLocationById($locationId);
        }
        return null;
    }
}
