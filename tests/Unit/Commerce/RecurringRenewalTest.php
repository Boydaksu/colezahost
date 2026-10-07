<?php

declare(strict_types=1);

namespace Tests\Unit\Commerce;

use Coleza\Domain\Commerce\Invoices\Invoice;
use Coleza\Domain\Commerce\Invoices\InvoiceService;
use Coleza\Domain\Commerce\Recurring\BillingPeriod;
use Coleza\Domain\Commerce\Recurring\RenewalInvoiceService;
use Coleza\Domain\Pricing\Entities\PriceCycle;
use Coleza\Domain\Tax\Services\TaxService;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class RecurringRenewalTest extends TestCase
{
    private Connection $db;
    private TaxService $taxService;
    private InvoiceService $invoiceService;
    private RenewalInvoiceService $renewalService;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->taxService = new TaxService($this->db);
        $this->taxService->ensureTables();
        $taxClass = $this->taxService->createTaxClass(['code' => 'standard', 'name' => 'Standard', 'is_default' => true]);
        $taxZone = $this->taxService->createTaxZone(['code' => 'TR', 'name' => 'Turkey', 'country_codes' => ['TR']]);
        $this->taxService->createTaxRate([
            'tax_class_id' => $taxClass->getId(),
            'tax_zone_id' => $taxZone->getId(),
            'name' => 'KDV %20',
            'rate_percent' => 20.0,
        ]);

        $this->invoiceService = new InvoiceService($this->db, null, $this->taxService);
        $this->invoiceService->ensureTables();

        $this->renewalService = new RenewalInvoiceService($this->db, $this->invoiceService, $this->taxService);
        $this->renewalService->ensureTables();
    }

    public function testBillingPeriodCalculationAcrossCycles(): void
    {
        // Monthly
        $nextMonthly = BillingPeriod::calculateNextDueDate('2026-05-15', PriceCycle::MONTHLY);
        $this->assertSame('2026-06-15', $nextMonthly);

        // Quarterly
        $nextQuarterly = BillingPeriod::calculateNextDueDate('2026-01-01', PriceCycle::QUARTERLY);
        $this->assertSame('2026-04-01', $nextQuarterly);

        // Semi-annually
        $nextSemi = BillingPeriod::calculateNextDueDate('2026-01-01', PriceCycle::SEMI_ANNUALLY);
        $this->assertSame('2026-07-01', $nextSemi);

        // Annually
        $nextAnnual = BillingPeriod::calculateNextDueDate('2026-03-10', PriceCycle::ANNUALLY);
        $this->assertSame('2027-03-10', $nextAnnual);

        // Biennially
        $nextBiennial = BillingPeriod::calculateNextDueDate('2026-01-01', PriceCycle::BIENNIALLY);
        $this->assertSame('2028-01-01', $nextBiennial);

        // Triennially
        $nextTriennial = BillingPeriod::calculateNextDueDate('2026-01-01', PriceCycle::TRIENNIALLY);
        $this->assertSame('2029-01-01', $nextTriennial);

        // One-time
        $nextOneTime = BillingPeriod::calculateNextDueDate('2026-01-01', PriceCycle::ONE_TIME);
        $this->assertSame('2026-01-01', $nextOneTime);
    }

    public function testRenewalLeadDaysWindowEvaluation(): void
    {
        $dueDate = '2026-11-01';

        // 17 days prior -> Not due yet (leadDays = 14)
        $this->assertFalse(BillingPeriod::isDueForRenewal($dueDate, '2026-10-15', 14));

        // Exactly 14 days prior -> Due
        $this->assertTrue(BillingPeriod::isDueForRenewal($dueDate, '2026-10-18', 14));

        // 10 days prior -> Due
        $this->assertTrue(BillingPeriod::isDueForRenewal($dueDate, '2026-10-22', 14));

        // On due date -> Due
        $this->assertTrue(BillingPeriod::isDueForRenewal($dueDate, '2026-11-01', 14));

        // Overdue -> Due
        $this->assertTrue(BillingPeriod::isDueForRenewal($dueDate, '2026-11-05', 14));
    }

    public function testGenerateRenewalInvoiceWithTaxAndSnapshots(): void
    {
        $serviceData = [
            'service_id' => 101,
            'user_id' => 55,
            'billing_cycle' => PriceCycle::MONTHLY,
            'next_due_date' => '2026-11-01',
            'recurring_amount_minor' => 5000,
            'currency_code' => 'TRY',
            'country_code' => 'TR',
            'description' => 'cPanel Shared Hosting Standard',
        ];

        $invoice = $this->renewalService->generateRenewalInvoice($serviceData);

        $this->assertNotNull($invoice->getId());
        $this->assertSame(55, $invoice->getUserId());
        $this->assertSame('TRY', $invoice->getCurrencyCode());
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->getStatus());

        // Subtotal = 5000, Tax = 20% (1000), Total = 6000
        $this->assertSame(5000, $invoice->getSubtotalMinor());
        $this->assertSame(1000, $invoice->getTaxTotalMinor());
        $this->assertSame(6000, $invoice->getTotalMinor());

        $this->assertCount(1, $invoice->getItems());
        $item = $invoice->getItems()[0];
        $this->assertSame(101, $item->getServiceId());
        $this->assertSame('cPanel Shared Hosting Standard (2026-11-01 to 2026-12-01)', $item->getDescription());
        $this->assertSame(5000, $item->getSubtotalMinor());
        $this->assertSame(1000, $item->getTaxAmountMinor());
        $this->assertSame(6000, $item->getTotalMinor());
    }

    public function testIdempotencyProtectionBlocksDuplicateInvoice(): void
    {
        $serviceData = [
            'service_id' => 202,
            'user_id' => 88,
            'billing_cycle' => PriceCycle::ANNUALLY,
            'next_due_date' => '2026-12-01',
            'recurring_amount_minor' => 24000,
            'currency_code' => 'USD',
            'description' => 'Dedicated Server Enterprise',
        ];

        // First generation
        $invoice1 = $this->renewalService->generateRenewalInvoice($serviceData);
        $this->assertNotNull($invoice1->getId());

        // Second generation (identical service and period)
        $invoice2 = $this->renewalService->generateRenewalInvoice($serviceData);
        $this->assertSame($invoice1->getId(), $invoice2->getId());
        $this->assertSame($invoice1->getInvoiceNumber(), $invoice2->getInvoiceNumber());

        // Verify only 1 record exists in DB
        $records = $this->db->select('SELECT * FROM renewal_records WHERE service_id = ?', [202]);
        $this->assertCount(1, $records);
    }

    public function testBatchProcessingDueServices(): void
    {
        $services = [
            [
                'service_id' => 1,
                'user_id' => 10,
                'billing_cycle' => PriceCycle::MONTHLY,
                'next_due_date' => '2026-11-02', // 12 days away -> DUE
                'recurring_amount_minor' => 1000,
                'currency_code' => 'USD',
            ],
            [
                'service_id' => 2,
                'user_id' => 10,
                'billing_cycle' => PriceCycle::MONTHLY,
                'next_due_date' => '2026-11-25', // 35 days away -> NOT due
                'recurring_amount_minor' => 2000,
                'currency_code' => 'USD',
            ],
            [
                'service_id' => 3,
                'user_id' => 11,
                'billing_cycle' => PriceCycle::QUARTERLY,
                'next_due_date' => '2026-10-30', // 9 days away -> DUE
                'recurring_amount_minor' => 4500,
                'currency_code' => 'USD',
            ],
        ];

        $generated = $this->renewalService->processBatchRenewals($services, asOfDate: '2026-10-21', leadDays: 14);

        $this->assertCount(2, $generated);
        $serviceIds = array_map(fn($inv) => $inv->getItems()[0]->getServiceId(), $generated);
        $this->assertContains(1, $serviceIds);
        $this->assertContains(3, $serviceIds);
        $this->assertNotContains(2, $serviceIds);
    }

    public function testAdvanceNextDueDateOnSettlement(): void
    {
        $advancedDate = RenewalInvoiceService::advanceNextDueDate('2026-11-01', PriceCycle::MONTHLY);
        $this->assertSame('2026-12-01', $advancedDate);

        $advancedAnnual = RenewalInvoiceService::advanceNextDueDate('2026-11-01', PriceCycle::ANNUALLY);
        $this->assertSame('2027-11-01', $advancedAnnual);
    }
}
