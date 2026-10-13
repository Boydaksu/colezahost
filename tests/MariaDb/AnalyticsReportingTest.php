<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class AnalyticsReportingTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\AnalyticsReportingCases;
    public function testConcurrentDailyWritersShareOneCompleteRow(): void
    {
        $this->reportingFixture(); \Coleza\Tests\Support\DailySourceFixture::invoice($this->db, 12345, 10000, 2345);
        $results = $this->runParallel('analyticsDay', 0);
        foreach ($results as $result) { self::assertSame('ok', $result['status']); }
        self::assertCount(1, array_unique(array_column($results, 'aggregation_id')));
        $rows = (new \Coleza\Domain\Analytics\ReadModels\ReadModelAggregationService($this->db))->getDailySeries('2026-10-13', '2026-10-13');
        self::assertCount(1, $rows); self::assertSame(123.45, $rows[0]->getGrossInvoiced());
    }
    public function testConcurrentMonthlyWritersShareOneRow(): void
    {
        $read = $this->reportingFixture(); $read->rebuildDateRange('2026-10-01', '2026-10-31');
        (new \Coleza\Domain\Analytics\Subscription\SubscriptionSnapshotService($this->db))->captureSnapshot('2026-10-31');
        $results = $this->runParallel('analyticsMonth', 0);
        foreach ($results as $result) { self::assertSame('ok', $result['status']); }
        self::assertCount(1, array_unique(array_column($results, 'aggregation_id')));
        self::assertCount(1, $read->getMonthlySeries('2026-10', '2026-10'));
    }
}
