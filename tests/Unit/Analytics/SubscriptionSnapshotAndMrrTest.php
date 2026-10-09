<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Analytics;

use Coleza\Domain\Analytics\Subscription\MrrCalculator;
use Coleza\Domain\Analytics\Subscription\SubscriptionSnapshotService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class SubscriptionSnapshotAndMrrTest extends TestCase
{
    private Connection $db;
    private SubscriptionSnapshotService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->createServicesTable();

        $this->service = new SubscriptionSnapshotService($this->db);
        $this->service->ensureTables();
    }

    private function createServicesTable(): void
    {
        $this->db->statement(
            'CREATE TABLE IF NOT EXISTS hosting_services (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INT NOT NULL,
                domain VARCHAR(255) NOT NULL,
                package_name VARCHAR(64) NOT NULL,
                billing_cycle VARCHAR(32) NOT NULL,
                recurring_amount DECIMAL(10,2) NOT NULL,
                currency VARCHAR(3) NOT NULL DEFAULT "USD",
                status VARCHAR(32) NOT NULL DEFAULT "active",
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )'
        );
    }

    public function testMrrCalculatorNormalizationAcrossCycles(): void
    {
        // Monthly
        $this->assertSame(25.00, MrrCalculator::normalizeToMonthly(25.00, 'monthly'));

        // Quarterly ($90 / 3 = $30)
        $this->assertSame(30.00, MrrCalculator::normalizeToMonthly(90.00, 'quarterly'));

        // Semi-annually ($180 / 6 = $30)
        $this->assertSame(30.00, MrrCalculator::normalizeToMonthly(180.00, 'semi_annually'));

        // Annually ($360 / 12 = $30)
        $this->assertSame(30.00, MrrCalculator::normalizeToMonthly(360.00, 'annually'));

        // Biennially ($720 / 24 = $30)
        $this->assertSame(30.00, MrrCalculator::normalizeToMonthly(720.00, 'biennially'));

        // Triennially ($1080 / 36 = $30)
        $this->assertSame(30.00, MrrCalculator::normalizeToMonthly(1080.00, 'triennially'));

        // Non-recurring
        $this->assertSame(0.00, MrrCalculator::normalizeToMonthly(150.00, 'one_time'));
        $this->assertSame(0.00, MrrCalculator::normalizeToMonthly(0.00, 'free'));

        // ARR Conversion
        $this->assertSame(360.00, MrrCalculator::toArr(30.00));
        $this->assertSame(12000.00, MrrCalculator::toArr(1000.00));
    }

    public function testCaptureSubscriptionSnapshotFromActiveServices(): void
    {
        // Seed active hosting services:
        // Service 1: User 1, Monthly $20 -> MRR $20
        $this->db->statement(
            "INSERT INTO hosting_services (user_id, domain, package_name, billing_cycle, recurring_amount, currency, status)
             VALUES (1, 'site1.com', 'Basic', 'monthly', 20.00, 'USD', 'active')"
        );
        // Service 2: User 1, Annually $120 -> MRR $10
        $this->db->statement(
            "INSERT INTO hosting_services (user_id, domain, package_name, billing_cycle, recurring_amount, currency, status)
             VALUES (1, 'site2.com', 'Pro', 'annually', 120.00, 'USD', 'active')"
        );
        // Service 3: User 2, Quarterly $60 -> MRR $20
        $this->db->statement(
            "INSERT INTO hosting_services (user_id, domain, package_name, billing_cycle, recurring_amount, currency, status)
             VALUES (2, 'site3.com', 'Basic', 'quarterly', 60.00, 'USD', 'active')"
        );
        // Service 4: Terminated service -> must NOT contribute to MRR
        $this->db->statement(
            "INSERT INTO hosting_services (user_id, domain, package_name, billing_cycle, recurring_amount, currency, status)
             VALUES (3, 'cancelled.com', 'Basic', 'monthly', 50.00, 'USD', 'terminated')"
        );

        $snapshot = $this->service->captureSnapshot('2026-10-01', 'USD');

        $this->assertSame('2026-10-01', $snapshot->getSnapshotDate());
        $this->assertSame('USD', $snapshot->getCurrency());
        $this->assertSame(3, $snapshot->getActiveSubscriptionsCount());
        $this->assertSame(2, $snapshot->getActiveCustomersCount());
        $this->assertSame(50.00, $snapshot->getTotalMrr()); // $20 + $10 + $20
        $this->assertSame(600.00, $snapshot->getTotalArr()); // 50 * 12
        $this->assertSame(25.00, $snapshot->getArpu()); // 50 / 2 customers
    }

    public function testSequentialSnapshotsCalculateNewAndChurnedMrr(): void
    {
        // Day 1: Single service, $50 MRR
        $this->db->statement(
            "INSERT INTO hosting_services (id, user_id, domain, package_name, billing_cycle, recurring_amount, currency, status)
             VALUES (1, 10, 'day1.com', 'VIP', 'monthly', 50.00, 'USD', 'active')"
        );
        $snap1 = $this->service->captureSnapshot('2026-10-01', 'USD');
        $this->assertSame(50.00, $snap1->getTotalMrr());
        $this->assertSame(0.00, $snap1->getNewMrr());

        // Day 2: Added second service, +$30 MRR (Total $80 MRR)
        $this->db->statement(
            "INSERT INTO hosting_services (id, user_id, domain, package_name, billing_cycle, recurring_amount, currency, status)
             VALUES (2, 20, 'day2.com', 'Cloud', 'monthly', 30.00, 'USD', 'active')"
        );
        $snap2 = $this->service->captureSnapshot('2026-10-02', 'USD');
        $this->assertSame(80.00, $snap2->getTotalMrr());
        $this->assertSame(30.00, $snap2->getNewMrr());
        $this->assertSame(0.00, $snap2->getChurnedMrr());
        $this->assertSame(30.00, $snap2->getNetMrrGrowth());

        // Day 3: First service terminates (-$50 MRR, Total $30 MRR)
        $this->db->statement("UPDATE hosting_services SET status = 'terminated' WHERE id = 1");
        $snap3 = $this->service->captureSnapshot('2026-10-03', 'USD');
        $this->assertSame(30.00, $snap3->getTotalMrr());
        $this->assertSame(0.00, $snap3->getNewMrr());
        $this->assertSame(50.00, $snap3->getChurnedMrr());
        $this->assertSame(-50.00, $snap3->getNetMrrGrowth());
    }

    public function testMrrWaterfallReportGeneration(): void
    {
        // Setup historical sequence
        $this->db->statement(
            "INSERT INTO hosting_services (id, user_id, domain, package_name, billing_cycle, recurring_amount, currency, status)
             VALUES (1, 1, 'alpha.com', 'A', 'monthly', 100.00, 'USD', 'active')"
        );
        $this->service->captureSnapshot('2026-10-01', 'USD');

        // Expansion on day 2
        $this->db->statement(
            "INSERT INTO hosting_services (id, user_id, domain, package_name, billing_cycle, recurring_amount, currency, status)
             VALUES (2, 2, 'beta.com', 'B', 'monthly', 25.00, 'USD', 'active')"
        );
        $this->service->captureSnapshot('2026-10-02', 'USD');

        $waterfall = $this->service->getMrrWaterfall('2026-10-01', '2026-10-02', 'USD');

        $this->assertSame(100.00, $waterfall->getStartingMrr());
        $this->assertSame(125.00, $waterfall->getEndingMrr());
        $this->assertSame(25.00, $waterfall->getNewMrr());
        $this->assertSame(25.00, $waterfall->getNetGrowth());
        $this->assertSame(25.00, $waterfall->getGrowthRatePercent());
    }

    public function testSnapshotDateValidation(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Snapshot date must follow YYYY-MM-DD format.');
        $this->service->captureSnapshot('invalid-date', 'USD');
    }

    public function testHistoricalSnapshotsRetrieval(): void
    {
        $this->db->statement(
            "INSERT INTO hosting_services (id, user_id, domain, package_name, billing_cycle, recurring_amount, currency, status)
             VALUES (1, 1, 'hist.com', 'H', 'monthly', 40.00, 'USD', 'active')"
        );

        $this->service->captureSnapshot('2026-10-01', 'USD');
        $this->service->captureSnapshot('2026-10-02', 'USD');
        $this->service->captureSnapshot('2026-10-03', 'USD');

        $snapshots = $this->service->getHistoricalSnapshots('2026-10-01', '2026-10-03', 'USD');
        $this->assertCount(3, $snapshots);
        $this->assertSame('2026-10-01', $snapshots[0]->getSnapshotDate());
        $this->assertSame('2026-10-02', $snapshots[1]->getSnapshotDate());
        $this->assertSame('2026-10-03', $snapshots[2]->getSnapshotDate());
    }
}
