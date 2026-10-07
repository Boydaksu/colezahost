<?php

declare(strict_types=1);

namespace Tests\Unit\Finance;

use Coleza\Domain\Finance\Currency\Currency;
use Coleza\Domain\Finance\Currency\CurrencyService;
use Coleza\Domain\Finance\Fx\FxSnapshot;
use Coleza\Domain\Finance\Fx\StaticFxRateProvider;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class CurrencyAndFxTest extends TestCase
{
    private Connection $db;
    private CurrencyService $service;
    private StaticFxRateProvider $fxProvider;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->fxProvider = new StaticFxRateProvider();
        // Setup base rates (1 TRY = 0.030 USD, 1 TRY = 0.028 EUR)
        $this->fxProvider->setRate('TRY', 'USD', 0.030);
        $this->fxProvider->setRate('TRY', 'EUR', 0.028);

        $this->service = new CurrencyService($this->db);
        $this->service->registerProvider($this->fxProvider);
        $this->service->ensureTables();
    }

    public function testCreateAndFormatCurrencies(): void
    {
        $try = $this->service->createCurrency([
            'code' => 'TRY',
            'name' => 'Turkish Lira',
            'symbol' => '₺',
            'format' => '{amount} {symbol}',
            'is_default' => true,
        ]);

        $usd = $this->service->createCurrency([
            'code' => 'USD',
            'name' => 'US Dollar',
            'symbol' => '$',
            'format' => '{symbol}{amount}',
            'is_default' => false,
        ]);

        $this->assertSame('TRY', $try->getCode());
        $this->assertTrue($try->isDefault());
        $this->assertSame('150.00 ₺', $try->formatAmount(15000)); // 15000 minor units = 150.00

        $this->assertSame('USD', $usd->getCode());
        $this->assertFalse($usd->isDefault());
        $this->assertSame('$29.99', $usd->formatAmount(2999)); // 2999 minor units = 29.99
    }

    public function testDuplicateCurrencyThrowsValidationException(): void
    {
        $this->service->createCurrency([
            'code' => 'EUR',
            'name' => 'Euro',
            'symbol' => '€',
        ]);

        $this->expectException(ValidationException::class);
        $this->service->createCurrency([
            'code' => 'EUR',
            'name' => 'Euro Duplicate',
            'symbol' => '€',
        ]);
    }

    public function testSettingNewDefaultCurrencyUnsetsPrevious(): void
    {
        $this->service->createCurrency([
            'code' => 'TRY',
            'name' => 'Turkish Lira',
            'symbol' => '₺',
            'is_default' => true,
        ]);

        $usd = $this->service->createCurrency([
            'code' => 'USD',
            'name' => 'US Dollar',
            'symbol' => '$',
            'is_default' => true,
        ]);

        $currentDefault = $this->service->getDefaultCurrency();
        $this->assertSame('USD', $currentDefault->getCode());

        $try = $this->service->findCurrency('TRY');
        $this->assertNotNull($try);
        $this->assertFalse($try->isDefault());
    }

    public function testCaptureFxSnapshotAndDeterministicConversions(): void
    {
        $this->service->createCurrency([
            'code' => 'TRY',
            'name' => 'Turkish Lira',
            'symbol' => '₺',
            'is_default' => true,
        ]);

        $this->service->createCurrency([
            'code' => 'USD',
            'name' => 'US Dollar',
            'symbol' => '$',
        ]);

        $this->service->createCurrency([
            'code' => 'EUR',
            'name' => 'Euro',
            'symbol' => '€',
        ]);

        // Capture snapshot for today
        $snapshot = $this->service->captureFxSnapshot('static', '2026-10-07');
        $this->assertNotNull($snapshot->getId());
        $this->assertSame('TRY', $snapshot->getBaseCurrency());
        $this->assertSame('static', $snapshot->getProvider());
        $this->assertSame('2026-10-07', $snapshot->getSnapshotDate());

        $rates = $snapshot->getRates();
        $this->assertSame(0.030, $rates['USD']);
        $this->assertSame(0.028, $rates['EUR']);

        // Test minor unit conversions
        // 10,000 TRY (100.00 TRY in minor units) -> USD at 0.030 = 300 minor units ($3.00)
        $usdMinor = $snapshot->convertFromBase(10000, 'USD');
        $this->assertSame(300, $usdMinor);

        // Convert back to base: 300 USD minor at 0.030 = 10,000 TRY minor
        $tryMinor = $snapshot->convertToBase(300, 'USD');
        $this->assertSame(10000, $tryMinor);

        // Retrieve historical snapshot by date
        $fetched = $this->service->getSnapshotByDate('2026-10-07');
        $this->assertNotNull($fetched);
        $this->assertSame($snapshot->getId(), $fetched->getId());
        $this->assertSame(0.030, $fetched->getRate('USD'));
    }

    public function testHistoricalImmutabilityPreservedEvenWhenLiveProviderChanges(): void
    {
        $this->service->createCurrency([
            'code' => 'TRY',
            'name' => 'Turkish Lira',
            'symbol' => '₺',
            'is_default' => true,
        ]);
        $this->service->createCurrency([
            'code' => 'USD',
            'name' => 'US Dollar',
            'symbol' => '$',
        ]);

        // Snapshot day 1
        $day1Snapshot = $this->service->captureFxSnapshot('static', '2026-10-01');

        // Rate changes on day 2
        $this->fxProvider->setRate('TRY', 'USD', 0.025);
        $day2Snapshot = $this->service->captureFxSnapshot('static', '2026-10-02');

        // Verify day 1 record remained completely unchanged (immutable snapshot)
        $historyDay1 = $this->service->getSnapshotByDate('2026-10-01');
        $this->assertSame(0.030, $historyDay1->getRate('USD'));

        // Verify day 2 record has the new rate
        $historyDay2 = $this->service->getSnapshotByDate('2026-10-02');
        $this->assertSame(0.025, $historyDay2->getRate('USD'));
    }
}
