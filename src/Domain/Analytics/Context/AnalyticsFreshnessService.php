<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Context;

use Coleza\Foundation\Database\Connection;
use DateTimeImmutable;

final class AnalyticsFreshnessService
{
    public function __construct(
        private readonly Connection $db,
        private readonly int $staleThresholdSeconds = 900 // 15 minutes
    ) {
    }

    /**
     * Evaluates data freshness by comparing latest transactional events with latest read model rebuild timestamps.
     */
    public function evaluateFreshness(string $currency = 'USD'): DataFreshnessMetadata
    {
        $now = new DateTimeImmutable();
        $nowStr = $now->format('Y-m-d H:i:s');

        // 1. Get latest aggregation timestamp
        $lastRebuiltAt = null;
        try {
            $row = $this->db->selectOne(
                "SELECT rebuilt_at FROM analytics_daily_aggregations
                 WHERE currency = :c ORDER BY rebuilt_at DESC LIMIT 1",
                ['c' => strtoupper($currency)]
            );
            if ($row !== null && isset($row['rebuilt_at'])) {
                $lastRebuiltAt = (string) $row['rebuilt_at'];
            }
        } catch (\Throwable) {
            // Ignore
        }

        if ($lastRebuiltAt === null) {
            return new DataFreshnessMetadata(
                asOfTimestamp: $nowStr,
                lastAggregatedAt: $nowStr,
                lagSeconds: 0,
                status: DataFreshnessStatus::REALTIME,
                isConsistent: true,
                notice: 'No historical read model snapshots found; system operating on live transactional queries.'
            );
        }

        $lastRebuiltDt = new DateTimeImmutable($lastRebuiltAt);
        $lagSeconds = max(0, $now->getTimestamp() - $lastRebuiltDt->getTimestamp());

        // 2. Classify status
        $status = match (true) {
            $lagSeconds <= 60 => DataFreshnessStatus::REALTIME,
            $lagSeconds <= $this->staleThresholdSeconds => DataFreshnessStatus::NEAR_REALTIME,
            default => DataFreshnessStatus::STALE,
        };

        // 3. Check for any transaction recorded AFTER lastRebuiltAt
        $isConsistent = true;
        try {
            $recentPayment = $this->db->selectOne(
                "SELECT id FROM payments WHERE created_at > :rebuilt LIMIT 1",
                ['rebuilt' => $lastRebuiltAt]
            );
            if ($recentPayment !== null) {
                $isConsistent = false;
            }
        } catch (\Throwable) {
            // Ignore
        }

        $notice = match ($status) {
            DataFreshnessStatus::REALTIME => 'Analytics data is fully real-time.',
            DataFreshnessStatus::NEAR_REALTIME => sprintf('Analytics data updated %d minutes ago.', (int) round($lagSeconds / 60)),
            DataFreshnessStatus::STALE => sprintf('Analytics data is stale by %d minutes. Background rebuild recommended.', (int) round($lagSeconds / 60)),
            DataFreshnessStatus::REBUILDING => 'Background aggregation rebuild in progress.',
        };

        return new DataFreshnessMetadata(
            asOfTimestamp: $nowStr,
            lastAggregatedAt: $lastRebuiltAt,
            lagSeconds: $lagSeconds,
            status: $status,
            isConsistent: $isConsistent,
            notice: $notice
        );
    }
}
