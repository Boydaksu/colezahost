<?php

declare(strict_types=1);

namespace Coleza\Domain\Servers\Placement;

use Coleza\Domain\Servers\Entities\Location;
use Coleza\Domain\Servers\Entities\Server;
use Coleza\Domain\Servers\Entities\ServerPool;

final class PlacementDecision
{
    /**
     * @param array<int, string> $rejectionReasons Map of server_id => reason
     */
    public function __construct(
        private bool $successful,
        private ?Server $selectedServer = null,
        private ?ServerPool $serverPool = null,
        private ?Location $location = null,
        private string $strategyUsed = 'none',
        private int $candidatesEvaluated = 0,
        private array $rejectionReasons = [],
        private string $message = '',
        private ?string $decidedAt = null
    ) {
        if ($this->decidedAt === null) {
            $this->decidedAt = date('Y-m-d H:i:s');
        }
    }

    /**
     * @param array<int, string> $rejectionReasons
     */
    public static function selected(
        Server $server,
        ?ServerPool $pool = null,
        ?Location $location = null,
        string $strategy = 'least_loaded',
        int $candidatesEvaluated = 1,
        array $rejectionReasons = []
    ): self {
        return new self(
            successful: true,
            selectedServer: $server,
            serverPool: $pool,
            location: $location,
            strategyUsed: $strategy,
            candidatesEvaluated: $candidatesEvaluated,
            rejectionReasons: $rejectionReasons,
            message: "Server '{$server->getHostname()}' selected using strategy '{$strategy}'."
        );
    }

    /**
     * @param array<int, string> $rejectionReasons
     */
    public static function rejected(
        string $message,
        array $rejectionReasons = [],
        int $candidatesEvaluated = 0
    ): self {
        return new self(
            successful: false,
            selectedServer: null,
            serverPool: null,
            location: null,
            strategyUsed: 'none',
            candidatesEvaluated: $candidatesEvaluated,
            rejectionReasons: $rejectionReasons,
            message: $message
        );
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    public function getSelectedServer(): ?Server
    {
        return $this->selectedServer;
    }

    public function getServerPool(): ?ServerPool
    {
        return $this->serverPool;
    }

    public function getLocation(): ?Location
    {
        return $this->location;
    }

    public function getStrategyUsed(): string
    {
        return $this->strategyUsed;
    }

    public function getCandidatesEvaluated(): int
    {
        return $this->candidatesEvaluated;
    }

    /**
     * @return array<int, string>
     */
    public function getRejectionReasons(): array
    {
        return $this->rejectionReasons;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getDecidedAt(): string
    {
        return $this->decidedAt ?? date('Y-m-d H:i:s');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'successful' => $this->successful,
            'selected_server' => $this->selectedServer?->toArray(),
            'server_pool' => $this->serverPool?->toArray(),
            'location' => $this->location?->toArray(),
            'strategy_used' => $this->strategyUsed,
            'candidates_evaluated' => $this->candidatesEvaluated,
            'rejection_reasons' => $this->rejectionReasons,
            'message' => $this->message,
            'decided_at' => $this->decidedAt,
        ];
    }
}
