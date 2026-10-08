<?php

declare(strict_types=1);

namespace Coleza\Domain\Servers\Placement;

use Coleza\Domain\Servers\Entities\Server;

final class DefaultServerHealthChecker implements ServerHealthCheckerInterface
{
    /** @var array<int, bool> */
    private array $simulatedHealth = [];

    public function setSimulatedHealth(int $serverId, bool $healthy): void
    {
        $this->simulatedHealth[$serverId] = $healthy;
    }

    public function checkHealth(Server $server): ServerHealthResult
    {
        if ($server->getId() !== null && isset($this->simulatedHealth[$server->getId()])) {
            return $this->simulatedHealth[$server->getId()]
                ? ServerHealthResult::healthy('Server healthy (simulated)')
                : ServerHealthResult::unhealthy('Server offline (simulated failure)');
        }

        if ($server->isMaintenance()) {
            return ServerHealthResult::unhealthy("Server '{$server->getHostname()}' is currently under maintenance.");
        }

        if ($server->isDisabled()) {
            return ServerHealthResult::unhealthy("Server '{$server->getHostname()}' is disabled.");
        }

        if ($server->isFull()) {
            return ServerHealthResult::unhealthy("Server '{$server->getHostname()}' has reached capacity limits.");
        }

        if (!$server->isActive()) {
            return ServerHealthResult::unhealthy("Server '{$server->getHostname()}' is in status: {$server->getStatus()}.");
        }

        if (trim($server->getHostname()) === '' || trim($server->getIpAddress()) === '') {
            return ServerHealthResult::unhealthy("Server '{$server->getName()}' has incomplete network configuration.");
        }

        return ServerHealthResult::healthy("Server '{$server->getHostname()}' is healthy and accepting placements.", 15);
    }
}
