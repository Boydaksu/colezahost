<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Subscription;

use Coleza\Domain\Audit\AuditLogger;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Database\PdoSchema;
use Coleza\Domain\Analytics\AnalyticsSchema;
use Coleza\Foundation\Exceptions\ValidationException;
use Coleza\Domain\Pricing\Entities\PriceCycle;
use DateTimeImmutable;

final class SubscriptionSnapshotService
{
    private string $tableName = 'analytics_subscription_snapshots';

    public function __construct(
        private readonly Connection $db,
        private readonly ?AuditLogger $auditLogger = null
    ) {
    }

    public static function schemaRequirements(): array
    {
        return ['analytics_subscription_snapshots' => ['id', 'snapshot_date', 'currency', 'active_subscriptions_count', 'active_customers_count', 'total_mrr', 'total_arr', 'new_mrr', 'expansion_mrr', 'contraction_mrr', 'churned_mrr', 'net_mrr_growth', 'arpu', 'metadata', 'created_at']];
    }

    public static function indexRequirements(): array
    {
        return ['analytics_subscription_snapshots' => ['snapshot_date', 'uq_snapshot_date_curr']];
    }

    public function ensureTables(bool $installSchema = false): void
    {
        AnalyticsSchema::assertCaller($this->db);
        $installed = PdoSchema::useInstalledSchema($this->db, self::schemaRequirements(), $installSchema);
        if ($installed) { AnalyticsSchema::ensureIndexes($this->db, self::indexRequirements(), $installSchema); return; }
        AnalyticsSchema::preflightExisting($this->db, self::indexRequirements());
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                snapshot_date VARCHAR(10) NOT NULL,
                currency VARCHAR(3) NOT NULL,
                active_subscriptions_count INT NOT NULL DEFAULT 0,
                active_customers_count INT NOT NULL DEFAULT 0,
                total_mrr DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                total_arr DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                new_mrr DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                expansion_mrr DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                contraction_mrr DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                churned_mrr DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                net_mrr_growth DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                arpu DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                metadata TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->tableName,
            $autoInc
        );
        $this->db->statement($sql . ($driver === 'mysql' ? ' ENGINE=InnoDB' : ''));
        AnalyticsSchema::ensureIndexes($this->db, self::indexRequirements(), true);
    }

    /**
     * Captures a point-in-time subscription and MRR snapshot for a given date.
     */
    public function captureSnapshot(string $date, string $currency = 'USD'): SubscriptionSnapshot
    {
        AnalyticsSchema::assertCaller($this->db);
        $parsedDate = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $date) {
            throw new ValidationException(['date' => ['Snapshot date must follow YYYY-MM-DD format.']], 'Snapshot date must follow YYYY-MM-DD format.');
        }

        $currency = strtoupper(trim($currency));

        if (!preg_match('/^[A-Z]{3}$/D', $currency)) { throw new ValidationException(['currency' => ['Invalid currency code.']], 'Invalid currency code.'); }
        $currencyRow = $this->db->selectOne('SELECT code, decimal_places FROM currencies WHERE code = ?', [$currency]);
        if ($currencyRow === null) { throw new ValidationException(['currency' => ['Currency must exist in the installed currency registry.']], 'Unknown analytics currency.'); }
        $places = filter_var($currencyRow['decimal_places'], FILTER_VALIDATE_INT);
        // Derived tables and existing MRR contracts store two decimals; do not silently truncate higher precision.
        if ($places === false || $places < 0 || $places > 2) { throw new \LogicException('Analytics currency precision above two decimals or invalid precision requires an explicit storage/metric upgrade.'); }
        $divisor = 10 ** $places;

        // Missing or incompatible canonical sources must fail before any snapshot or schema write.
        $services = $this->db->select(
            "SELECT id, user_id, billing_cycle, recurring_amount_minor FROM services WHERE status = 'active' AND currency_code = ?",
            [$currency]
        );

        $totalMrr = 0.0;
        $activeCount = 0;
        $uniqueUsers = [];

        foreach ($services as $svc) {
            $cycle = (string) $svc['billing_cycle'];
            if (!PriceCycle::isValid($cycle)) { throw new ValidationException(['billing_cycle' => ['Unknown canonical service billing cycle.']], 'Unknown analytics billing cycle.'); }
            $minor = filter_var($svc['recurring_amount_minor'], FILTER_VALIDATE_INT);
            $userId = filter_var($svc['user_id'], FILTER_VALIDATE_INT);
            if ($minor === false || $minor < 0 || $userId === false || $userId <= 0) {
                throw new \LogicException('Invalid canonical subscription amount or customer identity; explicit reconciliation required.');
            }
            if ($cycle === PriceCycle::ONE_TIME) { continue; }
            $amount = $minor / $divisor;
            $mrr = MrrCalculator::normalizeToMonthly($amount, $cycle);

            $totalMrr += $mrr;
            $activeCount++;
            $uniqueUsers[$userId] = true;
        }

        $this->ensureTables();

        $totalMrr = round($totalMrr, 2);
        $totalArr = MrrCalculator::toArr($totalMrr);
        $activeCustomers = count($uniqueUsers);
        $arpu = $activeCustomers > 0 ? round($totalMrr / $activeCustomers, 2) : 0.0;

        // 2. Retrieve previous snapshot to compute delta movements
        $prevSnapshot = $this->getLatestSnapshotBefore($date, $currency);

        $newMrr = 0.0;
        $expansionMrr = 0.0;
        $contractionMrr = 0.0;
        $churnedMrr = 0.0;

        if ($prevSnapshot !== null) {
            $previousMetadata = $prevSnapshot->getMetadata();
            if (($previousMetadata['source'] ?? null) !== 'services:canonical_minor_units' || ($previousMetadata['currency_decimal_places'] ?? null) !== $places) {
                throw new \LogicException('Historical subscription snapshot source or currency precision requires explicit reconciliation before calculating movements.');
            }
            $startingMrr = $prevSnapshot->getTotalMrr();
            $netDelta = round($totalMrr - $startingMrr, 2);

            if ($netDelta > 0) {
                $newMrr = $netDelta;
            } elseif ($netDelta < 0) {
                $churnedMrr = abs($netDelta);
            }
        }

        $netMrrGrowth = round($newMrr + $expansionMrr - $contractionMrr - $churnedMrr, 2);

        // 3. Upsert snapshot in database
        $existing = $this->getSnapshot($date, $currency);
        $now = date('Y-m-d H:i:s');

        $metadata = [
            'service_count' => $activeCount,
            'captured_at' => $now,
            'previous_snapshot_date' => $prevSnapshot?->getSnapshotDate(),
            'source' => 'services:canonical_minor_units',
            'currency_decimal_places' => $places,
        ];

        if ($existing !== null && $existing->getId() !== null) {
            $this->db->update(
                $this->tableName,
                [
                    'active_subscriptions_count' => $activeCount,
                    'active_customers_count' => $activeCustomers,
                    'total_mrr' => $totalMrr,
                    'total_arr' => $totalArr,
                    'new_mrr' => $newMrr,
                    'expansion_mrr' => $expansionMrr,
                    'contraction_mrr' => $contractionMrr,
                    'churned_mrr' => $churnedMrr,
                    'net_mrr_growth' => $netMrrGrowth,
                    'arpu' => $arpu,
                    'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'created_at' => $now,
                ],
                'id = :id',
                ['id' => $existing->getId()]
            );
            $id = $existing->getId();
        } else {
            $this->db->insert($this->tableName, [
                'snapshot_date' => $date,
                'currency' => $currency,
                'active_subscriptions_count' => $activeCount,
                'active_customers_count' => $activeCustomers,
                'total_mrr' => $totalMrr,
                'total_arr' => $totalArr,
                'new_mrr' => $newMrr,
                'expansion_mrr' => $expansionMrr,
                'contraction_mrr' => $contractionMrr,
                'churned_mrr' => $churnedMrr,
                'net_mrr_growth' => $netMrrGrowth,
                'arpu' => $arpu,
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
            ]);

            $row = $this->db->selectOne(
                "SELECT id FROM {$this->tableName} WHERE snapshot_date = :dt AND currency = :curr ORDER BY id DESC LIMIT 1",
                ['dt' => $date, 'curr' => $currency]
            );
            $id = $row !== null ? (int) $row['id'] : null;
        }

        $snapshot = new SubscriptionSnapshot(
            id: $id,
            snapshotDate: $date,
            currency: $currency,
            activeSubscriptionsCount: $activeCount,
            activeCustomersCount: $activeCustomers,
            totalMrr: $totalMrr,
            totalArr: $totalArr,
            newMrr: $newMrr,
            expansionMrr: $expansionMrr,
            contractionMrr: $contractionMrr,
            churnedMrr: $churnedMrr,
            netMrrGrowth: $netMrrGrowth,
            arpu: $arpu,
            metadata: $metadata,
            createdAt: $now
        );

        $this->auditLogger?->log(
            actorUserId: 0,
            eventType: 'ANALYTICS_SUBSCRIPTION_SNAPSHOT_CAPTURED',
            targetResource: 'analytics:snapshot:' . $date . ':' . $currency,
            payload: [
                'total_mrr' => $totalMrr,
                'total_arr' => $totalArr,
                'active_subscriptions' => $activeCount,
            ]
        );

        return $snapshot;
    }

    public function getSnapshot(string $date, string $currency = 'USD'): ?SubscriptionSnapshot
    {
        $this->ensureTables();
        $row = $this->db->selectOne(
            "SELECT * FROM {$this->tableName} WHERE snapshot_date = :dt AND currency = :curr LIMIT 1",
            ['dt' => $date, 'curr' => strtoupper(trim($currency))]
        );

        return $row !== null ? SubscriptionSnapshot::fromArray($row) : null;
    }

    public function getLatestSnapshotBefore(string $date, string $currency = 'USD'): ?SubscriptionSnapshot
    {
        $this->ensureTables();
        $row = $this->db->selectOne(
            "SELECT * FROM {$this->tableName}
             WHERE snapshot_date < :dt AND currency = :curr
             ORDER BY snapshot_date DESC LIMIT 1",
            ['dt' => $date, 'curr' => strtoupper(trim($currency))]
        );

        return $row !== null ? SubscriptionSnapshot::fromArray($row) : null;
    }

    /**
     * @return array<SubscriptionSnapshot>
     */
    public function getHistoricalSnapshots(string $startDate, string $endDate, string $currency = 'USD'): array
    {
        $this->ensureTables();
        $rows = $this->db->select(
            "SELECT * FROM {$this->tableName}
             WHERE snapshot_date >= :s AND snapshot_date <= :e AND currency = :curr
             ORDER BY snapshot_date ASC",
            ['s' => $startDate, 'e' => $endDate, 'curr' => strtoupper(trim($currency))]
        );

        return array_map(fn(array $r) => SubscriptionSnapshot::fromArray($r), $rows);
    }

    /**
     * Computes the MRR Movement Waterfall bridge between two snapshot points.
     */
    public function getMrrWaterfall(string $startDate, string $endDate, string $currency = 'USD'): MrrWaterfallReport
    {
        $this->ensureTables();
        $curr = strtoupper(trim($currency));

        $startSnapshot = $this->getSnapshot($startDate, $curr);
        $endSnapshot = $this->getSnapshot($endDate, $curr);

        $startingMrr = $startSnapshot !== null ? $startSnapshot->getTotalMrr() : 0.0;
        $endingMrr = $endSnapshot !== null ? $endSnapshot->getTotalMrr() : 0.0;

        // Sum movement increments across period
        $rows = $this->db->select(
            "SELECT SUM(new_mrr) as sum_new, SUM(expansion_mrr) as sum_exp,
                    SUM(contraction_mrr) as sum_con, SUM(churned_mrr) as sum_churn
             FROM {$this->tableName}
             WHERE snapshot_date > :s AND snapshot_date <= :e AND currency = :curr",
            ['s' => $startDate, 'e' => $endDate, 'curr' => $curr]
        );

        $newMrr = (float) ($rows[0]['sum_new'] ?? 0.0);
        $expansionMrr = (float) ($rows[0]['sum_exp'] ?? 0.0);
        $contractionMrr = (float) ($rows[0]['sum_con'] ?? 0.0);
        $churnedMrr = (float) ($rows[0]['sum_churn'] ?? 0.0);
        $reactivationMrr = 0.0;

        // If no intermediary snapshots existed, use direct endpoints
        if ($newMrr === 0.0 && $churnedMrr === 0.0 && $endingMrr !== $startingMrr) {
            $delta = $endingMrr - $startingMrr;
            if ($delta > 0) {
                $newMrr = $delta;
            } else {
                $churnedMrr = abs($delta);
            }
        }

        $netGrowth = round($endingMrr - $startingMrr, 2);
        $growthRate = $startingMrr > 0 ? round(($netGrowth / $startingMrr) * 100.0, 2) : 0.0;

        return new MrrWaterfallReport(
            startingMrr: $startingMrr,
            newMrr: round($newMrr, 2),
            expansionMrr: round($expansionMrr, 2),
            contractionMrr: round($contractionMrr, 2),
            churnedMrr: round($churnedMrr, 2),
            reactivationMrr: $reactivationMrr,
            endingMrr: $endingMrr,
            netGrowth: $netGrowth,
            growthRatePercent: $growthRate,
            currency: $curr,
            startDate: $startDate,
            endDate: $endDate
        );
    }
}
