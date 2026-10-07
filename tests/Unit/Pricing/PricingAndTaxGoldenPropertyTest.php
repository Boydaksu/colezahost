<?php

declare(strict_types=1);

namespace Tests\Unit\Pricing;

use Coleza\Domain\Finance\Currency\Currency;
use Coleza\Domain\Finance\Currency\CurrencyService;
use Coleza\Domain\Finance\Fx\FxSnapshot;
use Coleza\Domain\Finance\Fx\StaticFxRateProvider;
use Coleza\Domain\Pricing\Entities\PriceCycle;
use Coleza\Domain\Pricing\Entities\PriceOverride;
use Coleza\Domain\Pricing\Entities\PricePoint;
use Coleza\Domain\Pricing\Services\PricingService;
use Coleza\Domain\Tax\Entities\TaxClass;
use Coleza\Domain\Tax\Entities\TaxRate;
use Coleza\Domain\Tax\Entities\TaxZone;
use Coleza\Domain\Tax\Services\TaxService;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class PricingAndTaxGoldenPropertyTest extends TestCase
{
    private Connection $db;
    private CurrencyService $currencyService;
    private PricingService $pricingService;
    private TaxService $taxService;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->currencyService = new CurrencyService($this->db);
        $this->currencyService->ensureTables();

        $this->pricingService = new PricingService($this->db, $this->currencyService);
        $this->pricingService->ensureTables();

        $this->taxService = new TaxService($this->db);
        $this->taxService->ensureTables();
    }

    /**
     * Property 1: Tax Invariant.
     * In both exclusive and inclusive tax modes:
     * - gross_minor must equal subtotal_minor + tax_total_minor
     * - tax_total_minor >= 0
     * - subtotal_minor >= 0
     */
    public function testTaxCalculationInvariantsOverRandomAmounts(): void
    {
        $taxClass = $this->taxService->createTaxClass(['code' => 'std', 'name' => 'Standard']);
        $zone = $this->taxService->createTaxZone(['code' => 'ALL', 'name' => 'All', 'is_global' => true]);

        // Rate 1: 18% Exclusive
        $rateExclusive = $this->taxService->createTaxRate([
            'tax_class_id' => $taxClass->getId(),
            'tax_zone_id' => $zone->getId(),
            'name' => 'VAT 18 Exclusive',
            'rate_percent' => 18.0,
            'calculation_type' => TaxRate::CALCULATION_EXCLUSIVE,
        ]);

        // Rate 2: 20% Inclusive
        $rateInclusive = new TaxRate(
            id: 2,
            taxClassId: $taxClass->getId(),
            taxZoneId: $zone->getId(),
            name: 'VAT 20 Inclusive',
            ratePercent: 20.0,
            calculationType: TaxRate::CALCULATION_INCLUSIVE,
            priority: 1,
            isActive: true
        );

        // Run randomized property checks over 50 amount variations
        for ($i = 1; $i <= 50; $i++) {
            $amountMinor = $i * 137; // pseudo random minor units (e.g. 1.37 to 68.50)

            // 1. Exclusive check
            $exclusiveBreakdown = $rateExclusive->compute($amountMinor);
            $this->assertSame(
                $exclusiveBreakdown['gross_minor'],
                $exclusiveBreakdown['net_minor'] + $exclusiveBreakdown['tax_minor'],
                "Exclusive invariant failed for amount {$amountMinor}"
            );
            $this->assertGreaterThanOrEqual(0, $exclusiveBreakdown['tax_minor']);
            $this->assertGreaterThanOrEqual(0, $exclusiveBreakdown['net_minor']);

            // 2. Inclusive check
            $inclusiveBreakdown = $rateInclusive->compute($amountMinor);
            $this->assertSame(
                $inclusiveBreakdown['gross_minor'],
                $inclusiveBreakdown['net_minor'] + $inclusiveBreakdown['tax_minor'],
                "Inclusive invariant failed for amount {$amountMinor}"
            );
            $this->assertGreaterThanOrEqual(0, $inclusiveBreakdown['tax_minor']);
            $this->assertGreaterThanOrEqual(0, $inclusiveBreakdown['net_minor']);
            $this->assertSame($amountMinor, $inclusiveBreakdown['gross_minor']);
        }
    }

    /**
     * Property 2: Prorata Invariant.
     * For any valid daysUsed, daysTotal:
     * - 0 <= proratedMinor <= amountMinor
     * - proratedMinor(days) + proratedMinor(daysTotal - days) must be within 1 minor unit of amountMinor (rounding tolerance)
     */
    public function testProrataConservationInvariant(): void
    {
        $testAmounts = [1000, 4999, 12000, 19999, 100000];
        $totalDays = 30;

        foreach ($testAmounts as $amount) {
            for ($used = 0; $used <= $totalDays; $used++) {
                $usedProrata = $this->pricingService->calculateProrata($amount, $used, $totalDays);
                $remainingDays = $totalDays - $used;
                $remProrata = $this->pricingService->calculateProrata($amount, $remainingDays, $totalDays);

                $this->assertGreaterThanOrEqual(0, $usedProrata->getProratedMinor());
                $this->assertLessThanOrEqual($amount, $usedProrata->getProratedMinor());

                $sum = $usedProrata->getProratedMinor() + $remProrata->getProratedMinor();
                $diff = abs($sum - $amount);
                $this->assertLessThanOrEqual(
                    1,
                    $diff,
                    "Prorata conservation failed for amount {$amount}, used {$used}/{$totalDays}. Sum={$sum}, diff={$diff}"
                );
            }
        }
    }

    /**
     * Property 3: Golden Commercial Quote Snapshot.
     * Verifies end-to-end golden quote calculation for complex hosting commerce setup:
     * - Base Product: 500.00 TRY/mo
     * - 2 Configurable Options (OS: 150.00 TRY/mo, RAM: 80.00 TRY/mo + 20.00 setup)
     * - 1 Addon (Backup: 50.00 TRY/mo)
     * - Customer VIP override (10% discount)
     * - Tax applied (20% KDV exclusive)
     */
    public function testGoldenCommercialQuoteAndTaxCalculation(): void
    {
        $productId = 100;
        $osSubId = 201;
        $ramSubId = 202;
        $backupAddonId = 301;
        $vipCustomerId = 777;

        // Base Product: 500.00 TRY/mo + 50.00 setup
        $this->pricingService->setPricePoint([
            'target_type' => PricePoint::TARGET_PRODUCT,
            'target_id' => $productId,
            'currency_code' => 'TRY',
            'cycle' => PriceCycle::MONTHLY,
            'price_minor' => 50000,
            'setup_fee_minor' => 5000,
        ]);

        // OS: 150.00 TRY/mo
        $this->pricingService->setPricePoint([
            'target_type' => PricePoint::TARGET_OPTION,
            'target_id' => $osSubId,
            'currency_code' => 'TRY',
            'cycle' => PriceCycle::MONTHLY,
            'price_minor' => 15000,
            'setup_fee_minor' => 0,
        ]);

        // RAM: 80.00 TRY/mo + 20.00 setup
        $this->pricingService->setPricePoint([
            'target_type' => PricePoint::TARGET_OPTION,
            'target_id' => $ramSubId,
            'currency_code' => 'TRY',
            'cycle' => PriceCycle::MONTHLY,
            'price_minor' => 8000,
            'setup_fee_minor' => 2000,
        ]);

        // Backup Addon: 50.00 TRY/mo
        $this->pricingService->setPricePoint([
            'target_type' => PricePoint::TARGET_ADDON,
            'target_id' => $backupAddonId,
            'currency_code' => 'TRY',
            'cycle' => PriceCycle::MONTHLY,
            'price_minor' => 5000,
            'setup_fee_minor' => 0,
        ]);

        // 10% VIP discount on base product for VIP customer
        $this->pricingService->createPriceOverride([
            'scope' => PriceOverride::SCOPE_CUSTOMER,
            'scope_id' => $vipCustomerId,
            'target_type' => PricePoint::TARGET_PRODUCT,
            'target_id' => $productId,
            'override_type' => PriceOverride::TYPE_PERCENT,
            'override_value' => 10,
        ]);

        // Calculate commercial quote
        $quote = $this->pricingService->calculateQuote(
            productId: $productId,
            currencyCode: 'TRY',
            cycle: PriceCycle::MONTHLY,
            selectedOptionSubIds: [$osSubId, $ramSubId],
            selectedAddonIds: [$backupAddonId],
            customerId: $vipCustomerId
        );

        // Base price: 500.00 - 10% = 450.00 TRY (45000 minor)
        $this->assertSame(45000, $quote->getBasePriceMinor());
        // Options: 150 + 80 = 230.00 TRY (23000 minor)
        $this->assertSame(23000, $quote->getOptionsTotalMinor());
        // Addons: 50.00 TRY (5000 minor)
        $this->assertSame(5000, $quote->getAddonsTotalMinor());
        // Recurring total: 450 + 230 + 50 = 730.00 TRY (73000 minor)
        $this->assertSame(73000, $quote->getRecurringTotalMinor());

        // Setup fee total: 50 (product) + 20 (ram) = 70.00 TRY (7000 minor)
        $this->assertSame(7000, $quote->getSetupFeeMinor());

        // First payment: 730 + 70 = 800.00 TRY (80000 minor)
        $this->assertSame(80000, $quote->getFirstPaymentTotalMinor());

        // Now evaluate Tax on the First Payment total (800.00 TRY)
        $taxClass = $this->taxService->createTaxClass(['code' => 'hosting', 'name' => 'Hosting Services']);
        $taxZone = $this->taxService->createTaxZone(['code' => 'TR', 'name' => 'Turkey', 'country_codes' => ['TR']]);
        $this->taxService->createTaxRate([
            'tax_class_id' => $taxClass->getId(),
            'tax_zone_id' => $taxZone->getId(),
            'name' => 'KDV %20',
            'rate_percent' => 20.0,
            'calculation_type' => TaxRate::CALCULATION_EXCLUSIVE,
        ]);

        $taxResult = $this->taxService->calculateTax(
            amountMinor: $quote->getFirstPaymentTotalMinor(),
            taxClassId: $taxClass->getId(),
            countryCode: 'TR'
        );

        // 800.00 TRY subtotal + 20% (160.00 TRY) = 960.00 TRY gross total
        $this->assertSame(80000, $taxResult->getSubtotalMinor());
        $this->assertSame(16000, $taxResult->getTaxTotalMinor());
        $this->assertSame(96000, $taxResult->getTotalMinor());
    }

    /**
     * Property 4: Currency FX Historical Immutability Guarantee.
     * Demonstrates that changing exchange rates later does not mutate earlier recorded transactions.
     */
    public function testFxRateHistoricalIndependence(): void
    {
        $provider = new StaticFxRateProvider(['TRY' => ['USD' => 0.030]]);
        $this->currencyService->registerProvider($provider);

        $this->currencyService->createCurrency([
            'code' => 'TRY',
            'name' => 'Turkish Lira',
            'symbol' => '₺',
            'is_default' => true,
        ]);
        $this->currencyService->createCurrency([
            'code' => 'USD',
            'name' => 'US Dollar',
            'symbol' => '$',
        ]);

        $initialSnapshot = $this->currencyService->captureFxSnapshot('static', '2026-10-01');
        $convertedOnDay1 = $initialSnapshot->convertFromBase(10000, 'USD'); // 100 TRY -> 3.00 USD (300 minor)
        $this->assertSame(300, $convertedOnDay1);

        // Market shifts wildly
        $provider->setRate('TRY', 'USD', 0.020);
        $newSnapshot = $this->currencyService->captureFxSnapshot('static', '2026-10-07');
        $convertedOnDay7 = $newSnapshot->convertFromBase(10000, 'USD'); // 100 TRY -> 2.00 USD (200 minor)
        $this->assertSame(200, $convertedOnDay7);

        // Re-querying Day 1 must strictly return original 300 minor units
        $reloadedDay1 = $this->currencyService->getSnapshotByDate('2026-10-01');
        $this->assertSame(300, $reloadedDay1->convertFromBase(10000, 'USD'));
    }
}
