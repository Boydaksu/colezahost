<?php

declare(strict_types=1);

namespace Coleza\Domain\Servers\Entities;

final class ServerCapacity
{
    public function __construct(
        private int $maxAccounts = 100,
        private int $usedAccounts = 0,
        private int $diskCapacityMb = 0,
        private int $diskUsedMb = 0,
        private int $bandwidthCapacityMb = 0,
        private int $bandwidthUsedMb = 0,
        private int $memoryCapacityMb = 0,
        private int $memoryUsedMb = 0
    ) {
    }

    public function getMaxAccounts(): int
    {
        return $this->maxAccounts;
    }

    public function getUsedAccounts(): int
    {
        return $this->usedAccounts;
    }

    public function getDiskCapacityMb(): int
    {
        return $this->diskCapacityMb;
    }

    public function getDiskUsedMb(): int
    {
        return $this->diskUsedMb;
    }

    public function getBandwidthCapacityMb(): int
    {
        return $this->bandwidthCapacityMb;
    }

    public function getBandwidthUsedMb(): int
    {
        return $this->bandwidthUsedMb;
    }

    public function getMemoryCapacityMb(): int
    {
        return $this->memoryCapacityMb;
    }

    public function getMemoryUsedMb(): int
    {
        return $this->memoryUsedMb;
    }

    public function getAvailableAccounts(): int
    {
        return max(0, $this->maxAccounts - $this->usedAccounts);
    }

    public function getAccountUsagePercent(): float
    {
        if ($this->maxAccounts <= 0) {
            return 100.0;
        }
        return round(($this->usedAccounts / $this->maxAccounts) * 100, 2);
    }

    public function getDiskUsagePercent(): float
    {
        if ($this->diskCapacityMb <= 0) {
            return 0.0;
        }
        return round(($this->diskUsedMb / $this->diskCapacityMb) * 100, 2);
    }

    public function getBandwidthUsagePercent(): float
    {
        if ($this->bandwidthCapacityMb <= 0) {
            return 0.0;
        }
        return round(($this->bandwidthUsedMb / $this->bandwidthCapacityMb) * 100, 2);
    }

    public function hasAccountHeadroom(int $requiredAccounts = 1): bool
    {
        return ($this->usedAccounts + $requiredAccounts) <= $this->maxAccounts;
    }

    public function hasDiskHeadroom(int $requiredMb = 0): bool
    {
        if ($this->diskCapacityMb <= 0 || $requiredMb <= 0) {
            return true;
        }
        return ($this->diskUsedMb + $requiredMb) <= $this->diskCapacityMb;
    }

    public function hasBandwidthHeadroom(int $requiredMb = 0): bool
    {
        if ($this->bandwidthCapacityMb <= 0 || $requiredMb <= 0) {
            return true;
        }
        return ($this->bandwidthUsedMb + $requiredMb) <= $this->bandwidthCapacityMb;
    }

    public function canAcceptPlacement(int $requiredDiskMb = 0, int $requiredBandwidthMb = 0): bool
    {
        return $this->hasAccountHeadroom(1)
            && $this->hasDiskHeadroom($requiredDiskMb)
            && $this->hasBandwidthHeadroom($requiredBandwidthMb);
    }

    public function isAtCapacity(): bool
    {
        return !$this->hasAccountHeadroom(1);
    }

    /**
     * Compute a composite load score between 0.0 (idle) and 1.0 (fully saturated).
     */
    public function calculateLoadScore(): float
    {
        $accountRatio = $this->maxAccounts > 0 ? ($this->usedAccounts / $this->maxAccounts) : 1.0;
        $diskRatio = $this->diskCapacityMb > 0 ? ($this->diskUsedMb / $this->diskCapacityMb) : 0.0;
        $bwRatio = $this->bandwidthCapacityMb > 0 ? ($this->bandwidthUsedMb / $this->bandwidthCapacityMb) : 0.0;

        // Weight: accounts 60%, disk 25%, bandwidth 15%
        $score = ($accountRatio * 0.6) + ($diskRatio * 0.25) + ($bwRatio * 0.15);
        return round(min(1.0, max(0.0, $score)), 4);
    }

    public function increment(int $accounts = 1, int $diskMb = 0, int $bandwidthMb = 0): self
    {
        return new self(
            maxAccounts: $this->maxAccounts,
            usedAccounts: max(0, $this->usedAccounts + $accounts),
            diskCapacityMb: $this->diskCapacityMb,
            diskUsedMb: max(0, $this->diskUsedMb + $diskMb),
            bandwidthCapacityMb: $this->bandwidthCapacityMb,
            bandwidthUsedMb: max(0, $this->bandwidthUsedMb + $bandwidthMb),
            memoryCapacityMb: $this->memoryCapacityMb,
            memoryUsedMb: $this->memoryUsedMb
        );
    }

    public function decrement(int $accounts = 1, int $diskMb = 0, int $bandwidthMb = 0): self
    {
        return new self(
            maxAccounts: $this->maxAccounts,
            usedAccounts: max(0, $this->usedAccounts - $accounts),
            diskCapacityMb: $this->diskCapacityMb,
            diskUsedMb: max(0, $this->diskUsedMb - $diskMb),
            bandwidthCapacityMb: $this->bandwidthCapacityMb,
            bandwidthUsedMb: max(0, $this->bandwidthUsedMb - $bandwidthMb),
            memoryCapacityMb: $this->memoryCapacityMb,
            memoryUsedMb: $this->memoryUsedMb
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'max_accounts' => $this->maxAccounts,
            'used_accounts' => $this->usedAccounts,
            'available_accounts' => $this->getAvailableAccounts(),
            'account_usage_percent' => $this->getAccountUsagePercent(),
            'disk_capacity_mb' => $this->diskCapacityMb,
            'disk_used_mb' => $this->diskUsedMb,
            'disk_usage_percent' => $this->getDiskUsagePercent(),
            'bandwidth_capacity_mb' => $this->bandwidthCapacityMb,
            'bandwidth_used_mb' => $this->bandwidthUsedMb,
            'bandwidth_usage_percent' => $this->getBandwidthUsagePercent(),
            'memory_capacity_mb' => $this->memoryCapacityMb,
            'memory_used_mb' => $this->memoryUsedMb,
            'load_score' => $this->calculateLoadScore(),
            'is_at_capacity' => $this->isAtCapacity(),
        ];
    }
}
