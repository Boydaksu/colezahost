<?php

declare(strict_types=1);

namespace Tests\Unit\Pricing;

use Coleza\Domain\Pricing\Entities\PriceCycle;
use Coleza\Domain\Pricing\Entities\PricePoint;
use Coleza\Domain\Pricing\Services\PricingService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class PricingServiceTest extends TestCase
{
    private Connection $db;
    private PricingService $service;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->service = new PricingService($this->db);
        $this->service->ensureTables();
    }

    public function testSetAndRetrievePricePoints(): void
    {
        $productId = 1;
        $monthlyPoint = $this->service->setPricePoint([
            'target_type' => PricePoint::TARGET_PRODUCT,
            'target_id' => $productId,
            'currency_code' => 'TRY',
            'cycle' => PriceCycle::MONTHLY,
            'price_minor' => 19900, // 199.00 TRY
            'setup_fee_minor' => 5000, // 50.00 TRY
        ]);

        $this->assertNotNull($monthlyPoint->getId());
        $this->assertSame(19900, $monthlyPoint->getPriceMinor());
        $this->assertSame(5000, $monthlyPoint->getSetupFeeMinor());
        $this->assertSame(24900, $monthlyPoint->getTotalFirstPaymentMinor());

        $annualPoint = $this->service->setPricePoint([
            'target_type' => PricePoint::TARGET_PRODUCT,
            'target_id' => $productId,
            'currency_code' => 'TRY',
            'cycle' => PriceCycle::ANNUALLY,
            'price_minor' => 199000, // 1990.00 TRY
            'setup_fee_minor' => 0, // Free setup on annual
        ]);

        $this->assertSame(199000, $annualPoint->getPriceMinor());
        $this->assertSame(0, $annualPoint->getSetupFeeMinor());

        $prices = $this->service->getPricesForTarget(PricePoint::TARGET_PRODUCT, $productId, 'TRY');
        $this->assertCount(2, $prices);
    }

    public function testUpdateExistingPricePoint(): void
    {
        $productId = 5;
        $this->service->setPricePoint([
            'target_type' => PricePoint::TARGET_PRODUCT,
            'target_id' => $productId,
            'currency_code' => 'USD',
            'cycle' => PriceCycle::MONTHLY,
            'price_minor' => 999,
        ]);

        // Update price to 1099
        $updated = $this->service->setPricePoint([
            'target_type' => PricePoint::TARGET_PRODUCT,
            'target_id' => $productId,
            'currency_code' => 'USD',
            'cycle' => PriceCycle::MONTHLY,
            'price_minor' => 1099,
            'setup_fee_minor' => 200,
        ]);

        $this->assertSame(1099, $updated->getPriceMinor());
        $this->assertSame(200, $updated->getSetupFeeMinor());

        $fetched = $this->service->findPricePoint(PricePoint::TARGET_PRODUCT, $productId, 'USD', PriceCycle::MONTHLY);
        $this->assertNotNull($fetched);
        $this->assertSame(1099, $fetched->getPriceMinor());
    }

    public function testCalculateQuoteWithProductOptionsAndAddons(): void
    {
        $productId = 10;
        $subOptionOsId = 101; // e.g., Windows Server license option
        $addonBackupId = 201; // e.g., Daily automated backup addon

        // 1. Base Product: 500.00 TRY/mo + 100.00 TRY setup
        $this->service->setPricePoint([
            'target_type' => PricePoint::TARGET_PRODUCT,
            'target_id' => $productId,
            'currency_code' => 'TRY',
            'cycle' => PriceCycle::MONTHLY,
            'price_minor' => 50000,
            'setup_fee_minor' => 10000,
        ]);

        // 2. Option Sub: 150.00 TRY/mo + 0 setup
        $this->service->setPricePoint([
            'target_type' => PricePoint::TARGET_OPTION,
            'target_id' => $subOptionOsId,
            'currency_code' => 'TRY',
            'cycle' => PriceCycle::MONTHLY,
            'price_minor' => 15000,
            'setup_fee_minor' => 0,
        ]);

        // 3. Addon: 50.00 TRY/mo + 20.00 TRY setup
        $this->service->setPricePoint([
            'target_type' => PricePoint::TARGET_ADDON,
            'target_id' => $addonBackupId,
            'currency_code' => 'TRY',
            'cycle' => PriceCycle::MONTHLY,
            'price_minor' => 5000,
            'setup_fee_minor' => 2000,
        ]);

        $quote = $this->service->calculateQuote(
            productId: $productId,
            currencyCode: 'TRY',
            cycle: PriceCycle::MONTHLY,
            selectedOptionSubIds: [$subOptionOsId],
            selectedAddonIds: [$addonBackupId]
        );

        // Assertions on Authoritative Quote
        $this->assertSame('TRY', $quote->getCurrencyCode());
        $this->assertSame(PriceCycle::MONTHLY, $quote->getCycle());
        $this->assertSame(50000, $quote->getBasePriceMinor());
        $this->assertSame(15000, $quote->getOptionsTotalMinor());
        $this->assertSame(5000, $quote->getAddonsTotalMinor());
        $this->assertSame(70000, $quote->getRecurringTotalMinor()); // 500 + 150 + 50 = 700.00 TRY

        $this->assertSame(12000, $quote->getSetupFeeMinor()); // 100 (product) + 20 (addon) = 120.00 TRY
        $this->assertSame(82000, $quote->getFirstPaymentTotalMinor()); // 700 + 120 = 820.00 TRY
        $this->assertCount(3, $quote->getItems());
    }

    public function testValidationRejectsNegativePrices(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->setPricePoint([
            'target_type' => PricePoint::TARGET_PRODUCT,
            'target_id' => 1,
            'currency_code' => 'TRY',
            'cycle' => PriceCycle::MONTHLY,
            'price_minor' => -100,
        ]);
    }

    public function testMissingPriceThrowsExceptionDuringQuote(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->calculateQuote(
            productId: 999, // Unconfigured
            currencyCode: 'EUR',
            cycle: PriceCycle::MONTHLY
        );
    }
}
