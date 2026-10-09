<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Analytics;

use Coleza\Domain\Analytics\Context\AnalyticsFreshnessService;
use Coleza\Domain\Analytics\Context\CurrencyAnalyticsContext;
use Coleza\Domain\Analytics\Context\DataFreshnessStatus;
use Coleza\Domain\Analytics\Context\TimezoneAnalyticsContext;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class CurrencyTimezoneFreshnessTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->createTables();
    }

    private function createTables(): void
    {
        $this->db->statement(
            'CREATE TABLE IF NOT EXISTS analytics_daily_aggregations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                date VARCHAR(10) NOT NULL,
                currency VARCHAR(3) NOT NULL,
                rebuilt_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $this->db->statement(
            'CREATE TABLE IF NOT EXISTS payments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                amount DECIMAL(10,2) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )'
        );
    }

    public function testCurrencyContextUsesHistoricalRatesWithoutSilentRecalculation(): void
    {
        $ctx = new CurrencyAnalyticsContext(baseCurrency: 'USD');

        // Register distinct historical rates for different dates
        $ctx->registerHistoricalRate('2026-01-15', 'EUR', 'USD', 1.08);
        $ctx->registerHistoricalRate('2026-06-15', 'EUR', 'USD', 1.05);

        // 100 EUR in January was $108
        $janResult = $ctx->convertHistorical(100.0, 'EUR', 'USD', '2026-01-15');
        $this->assertSame(108.00, $janResult->getConvertedAmount());
        $this->assertSame(1.08, $janResult->getExchangeRateUsed());
        $this->assertSame('2026-01-15', $janResult->getRateDate());

        // 100 EUR in June was $105
        $junResult = $ctx->convertHistorical(100.0, 'EUR', 'USD', '2026-06-15');
        $this->assertSame(105.00, $junResult->getConvertedAmount());
        $this->assertSame(1.05, $junResult->getExchangeRateUsed());

        // Same currency returns identity
        $identity = $ctx->convertHistorical(50.0, 'USD', 'USD', '2026-10-01');
        $this->assertSame(50.0, $identity->getConvertedAmount());
        $this->assertSame(1.0, $identity->getExchangeRateUsed());

        // Missing rate throws ValidationException
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No historical exchange rate registered for GBP to USD');
        $ctx->convertHistorical(100.0, 'GBP', 'USD', '2026-01-01');
    }

    public function testTimezoneAnalyticsContextTranslatesLocalDayToUtcWindow(): void
    {
        // Europe/Istanbul (UTC+3 throughout the year)
        $istanbulCtx = new TimezoneAnalyticsContext('Europe/Istanbul');
        $windowIstanbul = $istanbulCtx->getUtcWindowForDate('2026-10-15');

        $this->assertSame('2026-10-14 21:00:00', $windowIstanbul['start']);
        $this->assertSame('2026-10-15 20:59:59', $windowIstanbul['end']);

        // America/New_York (EDT UTC-4 in October)
        $nyCtx = new TimezoneAnalyticsContext('America/New_York');
        $windowNy = $nyCtx->getUtcWindowForDate('2026-10-15');

        $this->assertSame('2026-10-15 04:00:00', $windowNy['start']);
        $this->assertSame('2026-10-16 03:59:59', $windowNy['end']);

        // UTC directly
        $utcCtx = new TimezoneAnalyticsContext('UTC');
        $windowUtc = $utcCtx->getUtcWindowForDate('2026-10-15');

        $this->assertSame('2026-10-15 00:00:00', $windowUtc['start']);
        $this->assertSame('2026-10-15 23:59:59', $windowUtc['end']);
    }

    public function testDataFreshnessEvaluatesRealtimeNearRealtimeAndStale(): void
    {
        $freshnessService = new AnalyticsFreshnessService($this->db, staleThresholdSeconds: 900);

        // Case 1: Fresh 30 seconds ago
        $now = date('Y-m-d H:i:s');
        $this->db->statement(
            "INSERT INTO analytics_daily_aggregations (date, currency, rebuilt_at) VALUES ('2026-10-15', 'USD', :r)",
            ['r' => $now]
        );

        $fresh = $freshnessService->evaluateFreshness('USD');
        $this->assertSame(DataFreshnessStatus::REALTIME, $fresh->getStatus());
        $this->assertTrue($fresh->isConsistent());

        // Case 2: Near real-time (5 minutes ago = 300s)
        $fiveMinAgo = date('Y-m-d H:i:s', time() - 300);
        $this->db->statement(
            "UPDATE analytics_daily_aggregations SET rebuilt_at = :r WHERE date = '2026-10-15'",
            ['r' => $fiveMinAgo]
        );
        $nearRealtime = $freshnessService->evaluateFreshness('USD');
        $this->assertSame(DataFreshnessStatus::NEAR_REALTIME, $nearRealtime->getStatus());

        // Case 3: Stale (2 hours ago = 7200s)
        $twoHoursAgo = date('Y-m-d H:i:s', time() - 7200);
        $this->db->statement(
            "UPDATE analytics_daily_aggregations SET rebuilt_at = :r WHERE date = '2026-10-15'",
            ['r' => $twoHoursAgo]
        );
        $stale = $freshnessService->evaluateFreshness('USD');
        $this->assertSame(DataFreshnessStatus::STALE, $stale->getStatus());

        // Case 4: Recent payment arrived after last rebuild -> inconsistent
        $this->db->statement("INSERT INTO payments (amount, created_at) VALUES (50.0, :now)", ['now' => $now]);
        $inconsistent = $freshnessService->evaluateFreshness('USD');
        $this->assertFalse($inconsistent->isConsistent());
    }
}
