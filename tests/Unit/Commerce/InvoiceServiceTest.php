<?php

declare(strict_types=1);

namespace Tests\Unit\Commerce;

use Coleza\Domain\Commerce\Invoices\Invoice;
use Coleza\Domain\Commerce\Invoices\InvoiceService;
use Coleza\Domain\Commerce\Orders\Order;
use Coleza\Domain\Commerce\Orders\OrderService;
use Coleza\Domain\Finance\Currency\Currency;
use Coleza\Domain\Finance\Currency\CurrencyService;
use Coleza\Domain\Finance\Fx\StaticFxRateProvider;
use Coleza\Domain\Pricing\Entities\PriceCycle;
use Coleza\Domain\Pricing\Entities\PricePoint;
use Coleza\Domain\Pricing\Services\PricingService;
use Coleza\Domain\Tax\Services\TaxService;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class InvoiceServiceTest extends TestCase
{
    private Connection $db;
    private CurrencyService $currencyService;
    private PricingService $pricingService;
    private TaxService $taxService;
    private OrderService $orderService;
    private InvoiceService $invoiceService;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        // Setup Currency Service with rates
        $fxProvider = new StaticFxRateProvider();
        $fxProvider->setRate('TRY', 'USD', 0.030);
        $this->currencyService = new CurrencyService($this->db);
        $this->currencyService->registerProvider($fxProvider);
        $this->currencyService->ensureTables();
        $this->currencyService->createCurrency([
            'code' => 'TRY',
            'name' => 'Turkish Lira',
            'symbol' => '₺',
            'minor_units' => 2,
            'is_default' => true,
        ]);
        $this->currencyService->createCurrency([
            'code' => 'USD',
            'name' => 'US Dollar',
            'symbol' => '$',
            'minor_units' => 2,
            'is_default' => false,
        ]);
        $this->currencyService->captureFxSnapshot('static', date('Y-m-d'));

        // Setup Pricing & Tax
        $this->pricingService = new PricingService($this->db);
        $this->pricingService->ensureTables();

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

        // Setup Order Service
        $this->orderService = new OrderService($this->db, $this->pricingService, $this->taxService);
        $this->orderService->ensureTables();

        // Setup Invoice Service
        $this->invoiceService = new InvoiceService($this->db, $this->currencyService, $this->taxService);
        $this->invoiceService->ensureTables();
    }

    public function testCreateInvoiceFromOrderWithSnapshots(): void
    {
        $order = $this->orderService->createOrder(
            orderData: [
                'user_id' => 42,
                'organization_id' => 10,
                'currency_code' => 'TRY',
                'country_code' => 'TR',
            ],
            itemsData: [
                [
                    'product_id' => 101,
                    'product_name' => 'Cloud VPS High CPU',
                    'unit_price_minor' => 10000,
                    'unit_setup_fee_minor' => 2000,
                    'quantity' => 1,
                ],
                [
                    'product_id' => 102,
                    'product_name' => 'Extra Dedicated IP',
                    'unit_price_minor' => 3000,
                    'unit_setup_fee_minor' => 0,
                    'quantity' => 2,
                ],
            ]
        );

        $invoice = $this->invoiceService->createInvoiceFromOrder($order, '2026-10-15');

        $this->assertNotNull($invoice->getId());
        $year = date('Y');
        $this->assertSame("INV-{$year}-000001", $invoice->getInvoiceNumber());
        $this->assertSame(42, $invoice->getUserId());
        $this->assertSame(10, $invoice->getOrganizationId());
        $this->assertSame($order->getId(), $invoice->getOrderId());
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->getStatus());
        $this->assertSame('TRY', $invoice->getCurrencyCode());

        // Subtotal = 12000 (item 1) + 6000 (item 2: 2 * 3000) = 18000 minor
        $this->assertSame(18000, $invoice->getSubtotalMinor());
        // Tax 20% = 3600 minor
        $this->assertSame(3600, $invoice->getTaxTotalMinor());
        // Total = 21600 minor
        $this->assertSame(21600, $invoice->getTotalMinor());
        $this->assertSame(0, $invoice->getPaidAmountMinor());
        $this->assertSame(21600, $invoice->getBalanceDueMinor());
        $this->assertSame('2026-10-15', $invoice->getDueDate());

        // Check currency snapshot
        $curSnap = $invoice->getCurrencySnapshot();
        $this->assertNotEmpty($curSnap);
        $this->assertSame('TRY', $curSnap['code']);
        $this->assertEquals(1.0, $curSnap['fx_rate']);
        $this->assertArrayHasKey('fx_snapshot_id', $curSnap);

        // Check tax snapshot
        $taxSnap = $invoice->getTaxSnapshot();
        $this->assertNotEmpty($taxSnap);
        $this->assertSame(3600, $taxSnap['tax_total_minor']);

        // Check line items
        $this->assertCount(2, $invoice->getItems());
        $item1 = $invoice->getItems()[0];
        $this->assertSame('Cloud VPS High CPU', $item1->getDescription());
        $this->assertSame(1, $item1->getQuantity());
        $this->assertSame(12000, $item1->getSubtotalMinor());
        $this->assertSame(2400, $item1->getTaxAmountMinor()); // 12000 / 18000 * 3600 = 2400
        $this->assertSame(14400, $item1->getTotalMinor());

        $item2 = $invoice->getItems()[1];
        $this->assertSame('Extra Dedicated IP', $item2->getDescription());
        $this->assertSame(2, $item2->getQuantity());
        $this->assertSame(6000, $item2->getSubtotalMinor());
        $this->assertSame(1200, $item2->getTaxAmountMinor()); // 6000 / 18000 * 3600 = 1200
        $this->assertSame(7200, $item2->getTotalMinor());
    }

    public function testSequentialInvoiceNumbering(): void
    {
        $order1 = $this->orderService->createOrder(
            orderData: ['user_id' => 1, 'currency_code' => 'USD'],
            itemsData: [['product_id' => 1, 'unit_price_minor' => 1000]]
        );
        $order2 = $this->orderService->createOrder(
            orderData: ['user_id' => 2, 'currency_code' => 'USD'],
            itemsData: [['product_id' => 2, 'unit_price_minor' => 2000]]
        );
        $order3 = $this->orderService->createOrder(
            orderData: ['user_id' => 3, 'currency_code' => 'USD'],
            itemsData: [['product_id' => 3, 'unit_price_minor' => 3000]]
        );

        $inv1 = $this->invoiceService->createInvoiceFromOrder($order1);
        $inv2 = $this->invoiceService->createInvoiceFromOrder($order2);
        $inv3 = $this->invoiceService->createInvoiceFromOrder($order3);

        $year = date('Y');
        $this->assertSame("INV-{$year}-000001", $inv1->getInvoiceNumber());
        $this->assertSame("INV-{$year}-000002", $inv2->getInvoiceNumber());
        $this->assertSame("INV-{$year}-000003", $inv3->getInvoiceNumber());
    }

    public function testFindInvoiceAndStatusUpdate(): void
    {
        $order = $this->orderService->createOrder(
            orderData: ['user_id' => 99, 'currency_code' => 'USD'],
            itemsData: [['product_id' => 1, 'unit_price_minor' => 5000]]
        );
        $invoice = $this->invoiceService->createInvoiceFromOrder($order);

        // Find by ID
        $foundById = $this->invoiceService->findInvoiceById($invoice->getId());
        $this->assertNotNull($foundById);
        $this->assertSame($invoice->getInvoiceNumber(), $foundById->getInvoiceNumber());
        $this->assertCount(1, $foundById->getItems());

        // Find by Number
        $foundByNum = $this->invoiceService->findInvoiceByNumber($invoice->getInvoiceNumber());
        $this->assertNotNull($foundByNum);
        $this->assertSame($invoice->getId(), $foundByNum->getId());

        // Update Status to Paid
        $paidTimestamp = '2026-10-08 14:00:00';
        $updated = $this->invoiceService->updateStatus($invoice->getId(), Invoice::STATUS_PAID, $paidTimestamp);
        $this->assertSame(Invoice::STATUS_PAID, $updated->getStatus());
        $this->assertTrue($updated->isPaid());
        $this->assertSame($paidTimestamp, $updated->getPaidAt());
    }

    public function testBalanceDueCalculation(): void
    {
        $invoice = new Invoice(
            id: 1,
            invoiceNumber: 'INV-2026-000001',
            userId: 5,
            organizationId: null,
            orderId: null,
            status: Invoice::STATUS_PARTIALLY_PAID,
            currencyCode: 'USD',
            subtotalMinor: 10000,
            taxTotalMinor: 2000,
            totalMinor: 12000,
            paidAmountMinor: 4000
        );

        $this->assertSame(8000, $invoice->getBalanceDueMinor());

        $overpaid = new Invoice(
            id: 2,
            invoiceNumber: 'INV-2026-000002',
            userId: 5,
            organizationId: null,
            orderId: null,
            status: Invoice::STATUS_PAID,
            currencyCode: 'USD',
            subtotalMinor: 10000,
            taxTotalMinor: 2000,
            totalMinor: 12000,
            paidAmountMinor: 15000
        );

        $this->assertSame(0, $overpaid->getBalanceDueMinor());
    }
}
