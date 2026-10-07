<?php

declare(strict_types=1);

namespace Tests\Unit\Commerce;

use Coleza\Domain\Commerce\Invoices\Invoice;
use Coleza\Domain\Commerce\Invoices\InvoiceService;
use Coleza\Domain\Commerce\Orders\Order;
use Coleza\Domain\Commerce\Orders\OrderService;
use Coleza\Domain\Commerce\Orders\OrderStateMachine;
use Coleza\Domain\Commerce\Payments\Payment;
use Coleza\Domain\Commerce\Payments\PaymentService;
use Coleza\Domain\Finance\Currency\CurrencyService;
use Coleza\Domain\Finance\Fx\StaticFxRateProvider;
use Coleza\Domain\Pricing\Services\PricingService;
use Coleza\Domain\Tax\Services\TaxService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class PaymentServiceTest extends TestCase
{
    private Connection $db;
    private CurrencyService $currencyService;
    private PricingService $pricingService;
    private TaxService $taxService;
    private OrderService $orderService;
    private InvoiceService $invoiceService;
    private PaymentService $paymentService;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->pricingService = new PricingService($this->db);
        $this->pricingService->ensureTables();

        $this->taxService = new TaxService($this->db);
        $this->taxService->ensureTables();

        $this->orderService = new OrderService($this->db, $this->pricingService, $this->taxService);
        $this->orderService->ensureTables();

        $this->invoiceService = new InvoiceService($this->db, null, $this->taxService);
        $this->invoiceService->ensureTables();

        $this->paymentService = new PaymentService($this->db, $this->invoiceService, $this->orderService);
        $this->paymentService->ensureTables();
    }

    public function testRecordDirectCompletedPaymentWithInvoiceAllocation(): void
    {
        $order = $this->orderService->createOrder(
            orderData: ['user_id' => 10, 'currency_code' => 'USD'],
            itemsData: [['product_id' => 1, 'unit_price_minor' => 5000]]
        );
        $invoice = $this->invoiceService->createInvoiceFromOrder($order);

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->getStatus());
        $this->assertSame(OrderStateMachine::STATUS_PENDING_PAYMENT, $order->getStatus());

        // Admin records direct cash/manual payment
        $payment = $this->paymentService->recordPayment([
            'user_id' => 10,
            'invoice_id' => $invoice->getId(),
            'payment_method' => Payment::METHOD_MANUAL,
            'amount_minor' => 5000,
            'currency_code' => 'USD',
            'transaction_reference' => 'CASH-REC-001',
            'notes' => 'Received cash at office desk',
        ]);

        $this->assertNotNull($payment->getId());
        $this->assertStringStartsWith('PAY-', $payment->getPaymentNumber());
        $this->assertSame(Payment::STATUS_COMPLETED, $payment->getStatus());
        $this->assertTrue($payment->isCompleted());
        $this->assertSame(5000, $payment->getAmountMinor());
        $this->assertSame(5000, $payment->getAllocatedAmountMinor());
        $this->assertSame(0, $payment->getUnallocatedAmountMinor());
        $this->assertCount(1, $payment->getAllocations());

        // Verify invoice is now paid
        $updatedInvoice = $this->invoiceService->findInvoiceById($invoice->getId());
        $this->assertNotNull($updatedInvoice);
        $this->assertSame(Invoice::STATUS_PAID, $updatedInvoice->getStatus());
        $this->assertSame(5000, $updatedInvoice->getPaidAmountMinor());
        $this->assertSame(0, $updatedInvoice->getBalanceDueMinor());
        $this->assertNotNull($updatedInvoice->getPaidAt());

        // Verify order transitioned to active
        $updatedOrder = $this->orderService->findOrderById($order->getId());
        $this->assertNotNull($updatedOrder);
        $this->assertSame(OrderStateMachine::STATUS_ACTIVE, $updatedOrder->getStatus());
    }

    public function testRecordPendingBankTransferAndApproval(): void
    {
        $order = $this->orderService->createOrder(
            orderData: ['user_id' => 20, 'currency_code' => 'USD'],
            itemsData: [['product_id' => 2, 'unit_price_minor' => 10000]]
        );
        $invoice = $this->invoiceService->createInvoiceFromOrder($order);

        // Client submits bank transfer notification
        $payment = $this->paymentService->recordPayment([
            'user_id' => 20,
            'invoice_id' => $invoice->getId(),
            'payment_method' => Payment::METHOD_BANK_TRANSFER,
            'amount_minor' => 10000,
            'currency_code' => 'USD',
            'transaction_reference' => 'WIRE-TR-998877',
            'proof_document_url' => 'https://storage.local/receipts/slip-20.pdf',
        ]);

        $this->assertSame(Payment::STATUS_PENDING, $payment->getStatus());
        $this->assertTrue($payment->isPending());
        $this->assertCount(0, $payment->getAllocations());

        // Invoice and order still unpaid / pending payment
        $freshInvoice = $this->invoiceService->findInvoiceById($invoice->getId());
        $this->assertSame(Invoice::STATUS_UNPAID, $freshInvoice->getStatus());
        $freshOrder = $this->orderService->findOrderById($order->getId());
        $this->assertSame(OrderStateMachine::STATUS_PENDING_PAYMENT, $freshOrder->getStatus());

        // Admin approves manual payment
        $approvedPayment = $this->paymentService->approveManualPayment($payment->getId(), 'Bank wire verified in account');
        $this->assertSame(Payment::STATUS_COMPLETED, $approvedPayment->getStatus());
        $this->assertNotNull($approvedPayment->getPaidAt());

        // Invoice now paid, order active
        $freshInvoice = $this->invoiceService->findInvoiceById($invoice->getId());
        $this->assertSame(Invoice::STATUS_PAID, $freshInvoice->getStatus());
        $this->assertSame(10000, $freshInvoice->getPaidAmountMinor());

        $freshOrder = $this->orderService->findOrderById($order->getId());
        $this->assertSame(OrderStateMachine::STATUS_ACTIVE, $freshOrder->getStatus());
    }

    public function testRejectPendingBankTransfer(): void
    {
        $payment = $this->paymentService->recordPayment([
            'user_id' => 30,
            'payment_method' => Payment::METHOD_BANK_TRANSFER,
            'amount_minor' => 4500,
            'currency_code' => 'USD',
            'transaction_reference' => 'INVALID-WIRE',
        ]);

        $this->assertSame(Payment::STATUS_PENDING, $payment->getStatus());

        $rejected = $this->paymentService->rejectManualPayment($payment->getId(), 'Wire receipt is unreadable');
        $this->assertSame(Payment::STATUS_FAILED, $rejected->getStatus());
        $this->assertStringContainsString('Wire receipt is unreadable', (string)$rejected->getNotes());

        // Approving non-pending payment throws ValidationException
        $this->expectException(ValidationException::class);
        $this->paymentService->approveManualPayment($payment->getId());
    }

    public function testAdvancePaymentAndManualAllocations(): void
    {
        // 1. Client deposits unallocated advance payment of $150 (15000 minor)
        $payment = $this->paymentService->recordPayment([
            'user_id' => 40,
            'payment_method' => Payment::METHOD_MANUAL,
            'amount_minor' => 15000,
            'currency_code' => 'USD',
            'status' => Payment::STATUS_COMPLETED,
        ]);

        $this->assertSame(15000, $payment->getUnallocatedAmountMinor());

        // 2. Invoice 1 for $50 (5000 minor)
        $order1 = $this->orderService->createOrder(
            orderData: ['user_id' => 40, 'currency_code' => 'USD'],
            itemsData: [['product_id' => 1, 'unit_price_minor' => 5000]]
        );
        $inv1 = $this->invoiceService->createInvoiceFromOrder($order1);

        // 3. Invoice 2 for $120 (12000 minor)
        $order2 = $this->orderService->createOrder(
            orderData: ['user_id' => 40, 'currency_code' => 'USD'],
            itemsData: [['product_id' => 2, 'unit_price_minor' => 12000]]
        );
        $inv2 = $this->invoiceService->createInvoiceFromOrder($order2);

        // Allocate $50 to Invoice 1
        $alloc1 = $this->paymentService->allocatePayment($payment->getId(), $inv1->getId(), 5000);
        $this->assertSame(5000, $alloc1->getAmountMinor());
        $this->assertSame($inv1->getId(), $alloc1->getInvoiceId());

        $updatedInv1 = $this->invoiceService->findInvoiceById($inv1->getId());
        $this->assertSame(Invoice::STATUS_PAID, $updatedInv1->getStatus());

        $refreshedPayment = $this->paymentService->findPaymentById($payment->getId());
        $this->assertSame(10000, $refreshedPayment->getUnallocatedAmountMinor());

        // Allocate remaining $100 to Invoice 2 (partially paying it)
        $alloc2 = $this->paymentService->allocatePayment($payment->getId(), $inv2->getId(), 10000);
        $this->assertSame(10000, $alloc2->getAmountMinor());

        $updatedInv2 = $this->invoiceService->findInvoiceById($inv2->getId());
        $this->assertSame(Invoice::STATUS_PARTIALLY_PAID, $updatedInv2->getStatus());
        $this->assertSame(10000, $updatedInv2->getPaidAmountMinor());
        $this->assertSame(2000, $updatedInv2->getBalanceDueMinor());

        $refreshedPayment2 = $this->paymentService->findPaymentById($payment->getId());
        $this->assertSame(0, $refreshedPayment2->getUnallocatedAmountMinor());

        // Over-allocating when unallocated is 0 throws ValidationException
        $this->expectException(ValidationException::class);
        $this->paymentService->allocatePayment($payment->getId(), $inv2->getId(), 1000);
    }

    public function testCurrencyMismatchRejection(): void
    {
        $order = $this->orderService->createOrder(
            orderData: ['user_id' => 50, 'currency_code' => 'USD'],
            itemsData: [['product_id' => 1, 'unit_price_minor' => 5000]]
        );
        $invoice = $this->invoiceService->createInvoiceFromOrder($order);

        $this->expectException(ValidationException::class);
        $this->paymentService->recordPayment([
            'user_id' => 50,
            'invoice_id' => $invoice->getId(),
            'currency_code' => 'TRY', // Mismatch!
            'amount_minor' => 5000,
        ]);
    }

    public function testListPaymentsForUserAndInvoice(): void
    {
        $payment1 = $this->paymentService->recordPayment([
            'user_id' => 77,
            'organization_id' => 999,
            'amount_minor' => 1000,
            'currency_code' => 'USD',
            'status' => Payment::STATUS_COMPLETED,
        ]);

        $payment2 = $this->paymentService->recordPayment([
            'user_id' => 77,
            'organization_id' => null,
            'amount_minor' => 2000,
            'currency_code' => 'USD',
            'status' => Payment::STATUS_COMPLETED,
        ]);

        $userPayments = $this->paymentService->listPaymentsForUser(77);
        $this->assertCount(2, $userPayments);

        $orgPayments = $this->paymentService->listPaymentsForUser(77, 999);
        $this->assertCount(1, $orgPayments);
        $this->assertSame(999, $orgPayments[0]->getOrganizationId());
    }
}
