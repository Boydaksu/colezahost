<?php

declare(strict_types=1);

namespace Tests\Unit\Tax;

use Coleza\Domain\Tax\Entities\TaxClass;
use Coleza\Domain\Tax\Entities\TaxRate;
use Coleza\Domain\Tax\Entities\TaxZone;
use Coleza\Domain\Tax\Services\TaxService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class TaxServiceTest extends TestCase
{
    private Connection $db;
    private TaxService $service;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->service = new TaxService($this->db);
        $this->service->ensureTables();
    }

    public function testCreateTaxClassAndZone(): void
    {
        $class = $this->service->createTaxClass([
            'code' => 'hosting',
            'name' => 'Hosting Services Tax Class',
            'is_default' => true,
        ]);

        $this->assertNotNull($class->getId());
        $this->assertSame('hosting', $class->getCode());
        $this->assertTrue($class->isDefault());

        $zoneTr = $this->service->createTaxZone([
            'code' => 'TR',
            'name' => 'Turkey Zone',
            'country_codes' => ['TR'],
        ]);

        $this->assertNotNull($zoneTr->getId());
        $this->assertTrue($zoneTr->matches('TR'));
        $this->assertFalse($zoneTr->matches('DE'));

        $zoneGlobal = $this->service->createTaxZone([
            'code' => 'GLOBAL',
            'name' => 'Rest of World',
            'is_global' => true,
        ]);
        $this->assertTrue($zoneGlobal->matches('US'));
    }

    public function testExclusiveTaxCalculation(): void
    {
        $class = $this->service->createTaxClass(['code' => 'standard', 'name' => 'Standard']);
        $zone = $this->service->createTaxZone(['code' => 'TR', 'name' => 'Turkey', 'country_codes' => ['TR']]);

        // 20% KDV (Exclusive)
        $this->service->createTaxRate([
            'tax_class_id' => $class->getId(),
            'tax_zone_id' => $zone->getId(),
            'name' => 'KDV %20',
            'rate_percent' => 20.0,
            'calculation_type' => TaxRate::CALCULATION_EXCLUSIVE,
        ]);

        // Subtotal = 100.00 TRY (10,000 minor)
        $result = $this->service->calculateTax(
            amountMinor: 10000,
            taxClassId: $class->getId(),
            countryCode: 'TR'
        );

        $this->assertFalse($result->isExempt());
        $this->assertSame(10000, $result->getSubtotalMinor());
        $this->assertSame(2000, $result->getTaxTotalMinor()); // 20.00 TRY tax
        $this->assertSame(12000, $result->getTotalMinor()); // 120.00 TRY gross total
        $this->assertCount(1, $result->getTaxLines());
        $this->assertSame('KDV %20', $result->getTaxLines()[0]['name']);
    }

    public function testInclusiveTaxCalculation(): void
    {
        $class = $this->service->createTaxClass(['code' => 'consumer', 'name' => 'Consumer Products']);
        $zone = $this->service->createTaxZone(['code' => 'UK', 'name' => 'United Kingdom', 'country_codes' => ['GB']]);

        // 20% VAT (Inclusive - advertised price includes tax)
        $this->service->createTaxRate([
            'tax_class_id' => $class->getId(),
            'tax_zone_id' => $zone->getId(),
            'name' => 'UK VAT 20%',
            'rate_percent' => 20.0,
            'calculation_type' => TaxRate::CALCULATION_INCLUSIVE,
        ]);

        // Advertised total = 120.00 GBP (12,000 minor)
        $result = $this->service->calculateTax(
            amountMinor: 12000,
            taxClassId: $class->getId(),
            countryCode: 'GB'
        );

        $this->assertFalse($result->isExempt());
        $this->assertSame(10000, $result->getSubtotalMinor()); // 100.00 net
        $this->assertSame(2000, $result->getTaxTotalMinor()); // 20.00 tax
        $this->assertSame(12000, $result->getTotalMinor()); // 120.00 gross total
    }

    public function testCustomerTaxExemption(): void
    {
        $class = $this->service->createTaxClass(['code' => 'standard', 'name' => 'Standard']);
        $zone = $this->service->createTaxZone(['code' => 'TR', 'name' => 'Turkey', 'country_codes' => ['TR']]);

        $this->service->createTaxRate([
            'tax_class_id' => $class->getId(),
            'tax_zone_id' => $zone->getId(),
            'name' => 'KDV %20',
            'rate_percent' => 20.0,
        ]);

        $customerId = 77;
        $this->service->setCustomerExemption(
            customerId: $customerId,
            isExempt: true,
            taxNumber: 'TR1234567890',
            reason: 'Export service agreement / diplomat tax free'
        );

        $result = $this->service->calculateTax(
            amountMinor: 10000,
            taxClassId: $class->getId(),
            countryCode: 'TR',
            customerId: $customerId
        );

        $this->assertTrue($result->isExempt());
        $this->assertSame(0, $result->getTaxTotalMinor());
        $this->assertSame(10000, $result->getTotalMinor());
        $this->assertEmpty($result->getTaxLines());
        $this->assertStringContainsString('diplomat tax free', $result->getExemptionReason());
    }

    public function testUnknownLocationFallsBackToGlobalOrZeroTax(): void
    {
        $class = $this->service->createTaxClass(['code' => 'standard', 'name' => 'Standard']);

        // Country JP has no matching zone
        $result = $this->service->calculateTax(
            amountMinor: 5000,
            taxClassId: $class->getId(),
            countryCode: 'JP'
        );

        $this->assertSame(0, $result->getTaxTotalMinor());
        $this->assertSame(5000, $result->getTotalMinor());
    }
}
