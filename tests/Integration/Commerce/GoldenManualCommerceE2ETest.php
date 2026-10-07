<?php

declare(strict_types=1);

namespace Tests\Integration\Commerce;

use Coleza\Domain\Commerce\Credit\CreditEntry;
use Coleza\Domain\Commerce\Credit\CreditService;
use Coleza\Domain\Commerce\Invoices\Invoice;
use Coleza\Domain\Commerce\Invoices\InvoiceService;
use Coleza\Domain\Commerce\Orders\Order;
use Coleza\Domain\Commerce\Orders\OrderService;
use Coleza\Domain\Commerce\Orders\OrderStateMachine;
use Coleza\Domain\Commerce\Payments\Payment;
use Coleza\Domain\Commerce\Payments\PaymentService;
use Coleza\Domain\Commerce\Recurring\BillingPeriod;
use Coleza\Domain\Commerce\Recurring\RenewalInvoiceService;
use Coleza\Domain\Commerce\Services\Service;
use Coleza\Domain\Commerce\Services\ServiceService;
use Coleza\Domain\Commerce\Services\ServiceStateMachine;
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

final class GoldenManualCommerceE2ETest extends TestCase
{
    private Connection $db;
    private CurrencyService $currencyService;
    private PricingService $pricingService;
    private TaxService $taxService;
    private OrderService $orderService;
    private InvoiceService $invoiceService;
    private PaymentService $paymentService;
    private CreditService $creditService;
    private RenewalInvoiceService $renewalService;
    private ServiceService $serviceService;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        // 1. Currency & FX
        $fxProvider = new StaticFxRateProvider();
        $fxProvider->setRate('USD', 'TRY', 32.50);
        $this->currencyService = new CurrencyService($this->db);
        $this->currencyService->registerProvider($fxProvider);
        $this->currencyService->ensureTables();
        $this->currencyService->createCurrency([
            'code' => 'USD',
            'name' => 'US Dollar',
            'symbol' => '$',
            'minor_units' => 2,
            'is_default' => true,
        ]);
        $this->currencyService->createCurrency([
            'code' => 'TRY',
            'name' => 'Turkish Lira',
            'symbol' => '₺',
            'minor_units' => 2,
            'is_default' => false,
        ]);
        $this->currencyService->captureFxSnapshot('static', date('Y-m-d'));

        // 2. Tax Engine
        $this->taxService = new TaxService($this->db);
        $this->taxService->ensureTables();
        $taxClass = $this->taxService->createTaxClass(['code' => 'standard', 'name' => 'Standard Rate', 'is_default' => true]);
        $taxZone = $this->taxService->createTaxZone(['code' => 'TR', 'name' => 'Turkey Zone', 'country_codes' => ['TR']]);
        $this->taxService->createTaxRate([
            'tax_class_id' => $taxClass->getId(),
            'tax_zone_id' => $taxZone->getId(),
            'name' => 'KDV %20',
            'rate_percent' => 20.0,
        ]);

        // 3. Pricing Service
        $this->pricingService = new PricingService($this->db);
        $this->pricingService->ensureTables();
        // Product 500: cPanel Cloud VPS Enterprise (100.00 TRY/mo + 20.00 TRY setup fee)
        $this->pricingService->setPricePoint([
            'target_type' => PricePoint::TARGET_PRODUCT,
            'target_id' => 500,
            'currency_code' => 'TRY',
            'cycle' => PriceCycle::MONTHLY,
            'price_minor' => 10000,
            'setup_fee_minor' => 2000,
        ]);

        // 4. Commerce Subsystems
        $this->orderService = new OrderService($this->db, $this->pricingService, $this->taxService);
        $this->orderService->ensureTables();

        $this->invoiceService = new InvoiceService($this->db, $this->currencyService, $this->taxService);
        $this->invoiceService->ensureTables();

        $this->paymentService = new PaymentService($this->db, $this->invoiceService, $this->orderService);
        $this->paymentService->ensureTables();

        $this->creditService = new CreditService($this->db, $this->invoiceService, $this->paymentService);
        $this->creditService->ensureTables();

        $this->renewalService = new RenewalInvoiceService($this->db, $this->invoiceService, $this->taxService);
        $this->renewalService->ensureTables();

        $this->serviceService = new ServiceService($this->db);
        $this->serviceService->ensureTables();
    }

    public function testGoldenManualCommerceVerticalEndToEnd(): void
    {
        $userId = 100;
        $countryCode = 'TR';

        // -------------------------------------------------------------
        // Step 1: Pre-existing Customer Store Credit
        // -------------------------------------------------------------
        $initialCredit = $this->creditService->addCredit(
            userId: $userId,
            amountMinor: 5000, // 50.00 TRY
            currencyCode: 'TRY',
            reason: 'Account recharge via promotional voucher'
        );
        $this->assertSame(5000, $this->creditService->getBalance($userId, 'TRY'));

        // -------------------------------------------------------------
        // Step 2: Order Creation with Authoritative Pricing and Tax
        // -------------------------------------------------------------
        $order = $this->orderService->createOrder(
            orderData: [
                'user_id' => $userId,
                'currency_code' => 'TRY',
                'country_code' => $countryCode,
                'notes' => 'New VPS order for business',
            ],
            itemsData: [
                [
                    'product_id' => 500,
                    'product_name' => 'cPanel Cloud VPS Enterprise',
                    'cycle' => PriceCycle::MONTHLY,
                    'quantity' => 1,
                    'domain' => 'mybusiness.com.tr',
                ],
            ]
        );

        $this->assertNotNull($order->getId());
        $this->assertStringStartsWith('ORD-', $order->getOrderNumber());
        $this->assertSame(OrderStateMachine::STATUS_PENDING_PAYMENT, $order->getStatus());
        // Subtotal = 100.00 (recurring) + 20.00 (setup) = 120.00 TRY (12000 minor)
        $this->assertSame(12000, $order->getSubtotalMinor());
        // Tax = 20% of 120.00 = 24.00 TRY (2400 minor)
        $this->assertSame(2400, $order->getTaxTotalMinor());
        // Total = 144.00 TRY (14400 minor)
        $this->assertSame(14400, $order->getTotalMinor());

        // -------------------------------------------------------------
        // Step 3: Invoice Finalization with Immutable Snapshots
        // -------------------------------------------------------------
        $invoice = $this->invoiceService->createInvoiceFromOrder($order, '2026-10-15');
        $this->assertNotNull($invoice->getId());
        $this->assertStringStartsWith('INV-', $invoice->getInvoiceNumber());
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->getStatus());
        $this->assertSame(14400, $invoice->getTotalMinor());
        $this->assertSame(14400, $invoice->getBalanceDueMinor());

        // Validate Currency Snapshot
        $curSnap = $invoice->getCurrencySnapshot();
        $this->assertSame('TRY', $curSnap['code']);
        $this->assertEquals(32.50, $curSnap['fx_rate']);

        // Validate Tax Snapshot
        $taxSnap = $invoice->getTaxSnapshot();
        $this->assertSame(2400, $taxSnap['tax_total_minor']);

        // -------------------------------------------------------------
        // Step 4: Split Payment (Partial Credit + Manual Bank Transfer)
        // -------------------------------------------------------------
        // Split Part 1: Apply 50.00 TRY (5000 minor) store credit
        $debitEntry = $this->creditService->applyCreditToInvoice(
            userId: $userId,
            invoiceId: $invoice->getId(),
            amountMinor: 5000
        );
        $this->assertSame(5000, $debitEntry->getAmountMinor());
        $this->assertSame(0, $this->creditService->getBalance($userId, 'TRY'));

        $partiallyPaidInvoice = $this->invoiceService->findInvoiceById($invoice->getId());
        $this->assertSame(Invoice::STATUS_PARTIALLY_PAID, $partiallyPaidInvoice->getStatus());
        $this->assertSame(5000, $partiallyPaidInvoice->getPaidAmountMinor());
        $this->assertSame(9400, $partiallyPaidInvoice->getBalanceDueMinor()); // 94.00 TRY remaining

        // Order is still pending payment
        $orderPending = $this->orderService->findOrderById($order->getId());
        $this->assertSame(OrderStateMachine::STATUS_PENDING_PAYMENT, $orderPending->getStatus());

        // Split Part 2: Customer submits Bank Transfer for remaining 9400 minor
        $bankPayment = $this->paymentService->recordPayment([
            'user_id' => $userId,
            'invoice_id' => $invoice->getId(),
            'payment_method' => Payment::METHOD_BANK_TRANSFER,
            'amount_minor' => 9400,
            'currency_code' => 'TRY',
            'transaction_reference' => 'WIRE-BATCH-991',
            'proof_document_url' => 'https://cdn.colezahost.com/receipts/bankslip-991.pdf',
        ]);
        $this->assertSame(Payment::STATUS_PENDING, $bankPayment->getStatus());

        // -------------------------------------------------------------
        // Step 5: Admin Approves Bank Payment & Settlement
        // -------------------------------------------------------------
        $approvedPayment = $this->paymentService->approveManualPayment($bankPayment->getId(), 'Approved by Admin');
        $this->assertSame(Payment::STATUS_COMPLETED, $approvedPayment->getStatus());

        // Invoice is now fully paid
        $paidInvoice = $this->invoiceService->findInvoiceById($invoice->getId());
        $this->assertSame(Invoice::STATUS_PAID, $paidInvoice->getStatus());
        $this->assertSame(14400, $paidInvoice->getPaidAmountMinor());
        $this->assertSame(0, $paidInvoice->getBalanceDueMinor());
        $this->assertTrue($paidInvoice->isPaid());

        // Order automatically transitioned to Active!
        $orderActive = $this->orderService->findOrderById($order->getId());
        $this->assertSame(OrderStateMachine::STATUS_ACTIVE, $orderActive->getStatus());
        $this->assertTrue($orderActive->isActive());

        // -------------------------------------------------------------
        // Step 6: Service Provisioning & Lifecycle Management
        // -------------------------------------------------------------
        $services = $this->serviceService->createServicesFromOrder($orderActive);
        $this->assertCount(1, $services);
        $service = $services[0];

        $this->assertSame(500, $service->getProductId());
        $this->assertSame('mybusiness.com.tr', $service->getDomain());
        $this->assertSame(ServiceStateMachine::STATUS_PENDING, $service->getStatus());
        $this->assertSame(10000, $service->getRecurringAmountMinor());

        // Admin provisions service
        $activeService = $this->serviceService->activateService($service->getId(), [
            'username' => 'bizuser',
            'server_name' => 'cpanel-ist-01',
            'ip_address' => '185.120.40.10',
        ]);
        $this->assertSame(ServiceStateMachine::STATUS_ACTIVE, $activeService->getStatus());
        $this->assertSame('cpanel-ist-01', $activeService->getServerName());
        $this->assertSame('185.120.40.10', $activeService->getIpAddress());

        // -------------------------------------------------------------
        // Step 7: Recurring Renewal Invoice Generation (Idempotent)
        // -------------------------------------------------------------
        $renewalServiceData = [
            'service_id' => $activeService->getId(),
            'user_id' => $userId,
            'billing_cycle' => $activeService->getBillingCycle(),
            'next_due_date' => $activeService->getNextDueDate(),
            'recurring_amount_minor' => $activeService->getRecurringAmountMinor(),
            'currency_code' => 'TRY',
            'country_code' => 'TR',
            'description' => 'cPanel Cloud VPS Enterprise Renewal',
        ];

        // Generation 1
        $renewalInvoice1 = $this->renewalService->generateRenewalInvoice($renewalServiceData);
        $this->assertNotNull($renewalInvoice1->getId());
        $this->assertNotSame($invoice->getId(), $renewalInvoice1->getId());
        $this->assertSame(10000, $renewalInvoice1->getSubtotalMinor()); // Only recurring, no setup fee!
        $this->assertSame(2000, $renewalInvoice1->getTaxTotalMinor()); // 20% KDV = 2000
        $this->assertSame(12000, $renewalInvoice1->getTotalMinor());
        $this->assertSame(Invoice::STATUS_UNPAID, $renewalInvoice1->getStatus());

        // Generation 2 (Idempotency Guard check)
        $renewalInvoice2 = $this->renewalService->generateRenewalInvoice($renewalServiceData);
        $this->assertSame($renewalInvoice1->getId(), $renewalInvoice2->getId());
        $this->assertSame($renewalInvoice1->getInvoiceNumber(), $renewalInvoice2->getInvoiceNumber());

        // -------------------------------------------------------------
        // Step 8: Settling Renewal Invoice and Due Date Advancement
        // -------------------------------------------------------------
        // Deposit fresh funds into credit and settle renewal invoice
        $this->creditService->addCredit($userId, 12000, 'TRY', 'Recharge for renewal');
        $this->creditService->applyCreditToInvoice($userId, $renewalInvoice1->getId(), 12000);

        $paidRenewal = $this->invoiceService->findInvoiceById($renewalInvoice1->getId());
        $this->assertSame(Invoice::STATUS_PAID, $paidRenewal->getStatus());
        $this->assertSame(0, $paidRenewal->getBalanceDueMinor());

        // Renew service (advances next due date)
        $previousDueDate = $activeService->getNextDueDate();
        $renewedService = $this->serviceService->renewService($activeService->getId());
        $this->assertNotSame($previousDueDate, $renewedService->getNextDueDate());
        $expectedNewDueDate = BillingPeriod::calculateNextDueDate($previousDueDate, PriceCycle::MONTHLY);
        $this->assertSame($expectedNewDueDate, $renewedService->getNextDueDate());

        // -------------------------------------------------------------
        // Step 9: Suspension & Unsuspension Workflow
        // -------------------------------------------------------------
        $suspendedService = $this->serviceService->suspendService($renewedService->getId(), 'Routine security check');
        $this->assertSame(ServiceStateMachine::STATUS_SUSPENDED, $suspendedService->getStatus());
        $this->assertSame('Routine security check', $suspendedService->getSuspensionReason());

        $unsuspendedService = $this->serviceService->unsuspendService($renewedService->getId());
        $this->assertSame(ServiceStateMachine::STATUS_ACTIVE, $unsuspendedService->getStatus());
        $this->assertNull($unsuspendedService->getSuspensionReason());

        // -------------------------------------------------------------
        // Step 10: Invariant and Audit Check
        // -------------------------------------------------------------
        // Customer ending credit balance is 0
        $this->assertSame(0, $this->creditService->getBalance($userId, 'TRY'));

        // Payments recorded for user
        $allPayments = $this->paymentService->listPaymentsForUser($userId);
        $this->assertCount(3, $allPayments); // 1 credit for order, 1 bank for order, 1 credit for renewal

        $totalAllocatedAcrossAllPayments = 0;
        foreach ($allPayments as $p) {
            $totalAllocatedAcrossAllPayments += $p->getAllocatedAmountMinor();
        }
        // Total allocated: 5000 + 9400 + 12000 = 26400 minor
        $this->assertSame(26400, $totalAllocatedAcrossAllPayments);
        $this->assertSame(
            $invoice->getTotalMinor() + $renewalInvoice1->getTotalMinor(),
            $totalAllocatedAcrossAllPayments
        );
    }
}
