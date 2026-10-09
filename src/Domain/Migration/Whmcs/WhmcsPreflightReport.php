<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Whmcs;

use JsonSerializable;

final class WhmcsPreflightReport implements JsonSerializable
{
    /**
     * @param array{orphan_services: int, orphan_domains: int, orphan_invoices: int} $integrityStats
     * @param list<string> $checksPassed
     * @param list<string> $warnings
     * @param list<string> $blockers
     */
    public function __construct(
        private string $status, // 'READY', 'READY_WITH_WARNINGS', 'INCOMPATIBLE'
        private WhmcsCapabilityProfile $capabilityProfile,
        private array $integrityStats,
        private int $totalEstimatedEntities,
        private array $checksPassed = [],
        private array $warnings = [],
        private array $blockers = []
    ) {
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isReady(): bool
    {
        return $this->status === 'READY' || $this->status === 'READY_WITH_WARNINGS';
    }

    public function getCapabilityProfile(): WhmcsCapabilityProfile
    {
        return $this->capabilityProfile;
    }

    /**
     * @return array{orphan_services: int, orphan_domains: int, orphan_invoices: int}
     */
    public function getIntegrityStats(): array
    {
        return $this->integrityStats;
    }

    public function getTotalEstimatedEntities(): int
    {
        return $this->totalEstimatedEntities;
    }

    /**
     * @return list<string>
     */
    public function getChecksPassed(): array
    {
        return $this->checksPassed;
    }

    /**
     * @return list<string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return list<string>
     */
    public function getBlockers(): array
    {
        return $this->blockers;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'is_ready' => $this->isReady(),
            'total_estimated_entities' => $this->totalEstimatedEntities,
            'integrity_stats' => $this->integrityStats,
            'checks_passed' => $this->checksPassed,
            'warnings' => $this->warnings,
            'blockers' => $this->blockers,
            'capability_profile' => $this->capabilityProfile->toArray(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
