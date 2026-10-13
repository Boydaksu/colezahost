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
        \Coleza\Tests\Support\SubscriptionSourceFixture::install($this->db);
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
            "INSERT INTO services (user_id, domain, billing_cycle, status, recurring_amount_minor, currency_code, service_number, product_id, registration_date, next_due_date) VALUES (1, 'site1.com', 'monthly', 'active', 2000, 'USD', 'FIX-1', 1, '2026-01-01', '2026-11-01')"
        );
        // Service 2: User 1, Annually $120 -> MRR $10
        $this->db->statement(
            "INSERT INTO services (user_id, domain, billing_cycle, status, recurring_amount_minor, currency_code, service_number, product_id, registration_date, next_due_date) VALUES (1, 'site2.com', 'annually', 'active', 12000, 'USD', 'FIX-2', 1, '2026-01-01', '2026-11-01')"
        );
        // Service 3: User 2, Quarterly $60 -> MRR $20
        $this->db->statement(
            "INSERT INTO services (user_id, domain, billing_cycle, status, recurring_amount_minor, currency_code, service_number, product_id, registration_date, next_due_date) VALUES (2, 'site3.com', 'quarterly', 'active', 6000, 'USD', 'FIX-3', 1, '2026-01-01', '2026-11-01')"
        );
        // Service 4: Terminated service -> must NOT contribute to MRR
        $this->db->statement(
            "INSERT INTO services (user_id, domain, billing_cycle, status, recurring_amount_minor, currency_code, service_number, product_id, registration_date, next_due_date) VALUES (3, 'cancelled.com', 'monthly', 'terminated', 5000, 'USD', 'FIX-4', 1, '2026-01-01', '2026-11-01')"
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
            "INSERT INTO services (id, user_id, domain, billing_cycle, status, recurring_amount_minor, currency_code, service_number, product_id, registration_date, next_due_date) VALUES (1, 10, 'day1.com', 'monthly', 'active', 5000, 'USD', 'FIX-5', 1, '2026-01-01', '2026-11-01')"
        );
        $snap1 = $this->service->captureSnapshot('2026-10-01', 'USD');
        $this->assertSame(50.00, $snap1->getTotalMrr());
        $this->assertSame(0.00, $snap1->getNewMrr());

        // Day 2: Added second service, +$30 MRR (Total $80 MRR)
        $this->db->statement(
            "INSERT INTO services (id, user_id, domain, billing_cycle, status, recurring_amount_minor, currency_code, service_number, product_id, registration_date, next_due_date) VALUES (2, 20, 'day2.com', 'monthly', 'active', 3000, 'USD', 'FIX-6', 1, '2026-01-01', '2026-11-01')"
        );
        $snap2 = $this->service->captureSnapshot('2026-10-02', 'USD');
        $this->assertSame(80.00, $snap2->getTotalMrr());
        $this->assertSame(30.00, $snap2->getNewMrr());
        $this->assertSame(0.00, $snap2->getChurnedMrr());
        $this->assertSame(30.00, $snap2->getNetMrrGrowth());

        // Day 3: First service terminates (-$50 MRR, Total $30 MRR)
        $this->db->statement("UPDATE services SET status = 'terminated' WHERE id = 1");
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
            "INSERT INTO services (id, user_id, domain, billing_cycle, status, recurring_amount_minor, currency_code, service_number, product_id, registration_date, next_due_date) VALUES (1, 1, 'alpha.com', 'monthly', 'active', 10000, 'USD', 'FIX-7', 1, '2026-01-01', '2026-11-01')"
        );
        $this->service->captureSnapshot('2026-10-01', 'USD');

        // Expansion on day 2
        $this->db->statement(
            "INSERT INTO services (id, user_id, domain, billing_cycle, status, recurring_amount_minor, currency_code, service_number, product_id, registration_date, next_due_date) VALUES (2, 2, 'beta.com', 'monthly', 'active', 2500, 'USD', 'FIX-8', 1, '2026-01-01', '2026-11-01')"
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
            "INSERT INTO services (id, user_id, domain, billing_cycle, status, recurring_amount_minor, currency_code, service_number, product_id, registration_date, next_due_date) VALUES (1, 1, 'hist.com', 'monthly', 'active', 4000, 'USD', 'FIX-9', 1, '2026-01-01', '2026-11-01')"
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
