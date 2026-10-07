<?php

declare(strict_types=1);

namespace Tests\Unit\Commerce;

use Coleza\Domain\Commerce\Invoices\Invoice;
use Coleza\Domain\Commerce\Invoices\InvoiceService;
use Coleza\Domain\Commerce\Orders\OrderService;
use Coleza\Domain\Commerce\Orders\OrderStateMachine;
use Coleza\Domain\Commerce\Payments\Payment;
use Coleza\Domain\Commerce\Payments\PaymentService;
use Coleza\Domain\Commerce\Payments\Refund;
use Coleza\Domain\Pricing\Services\PricingService;
use Coleza\Domain\Tax\Services\TaxService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class PartialPaymentAndRefundTest extends TestCase
{
    private Connection $db;
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

    public function testPartialSplitPaymentsOnSingleInvoice(): void
    {
        $userId = 100;
        $order = $this->orderService->createOrder(
            orderData: ['user_id' => $userId, 'currency_code' => 'USD'],
            itemsData: [['product_id' => 10, 'unit_price_minor' => 20000]]
        );
        $invoice = $this->invoiceService->createInvoiceFromOrder($order);

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->getStatus());
        $this->assertSame(20000, $invoice->getBalanceDueMinor());

        // Split 1: First payment of $80 (8000 minor)
        $payment1 = $this->paymentService->recordPayment([
            'user_id' => $userId,
            'invoice_id' => $invoice->getId(),
            'payment_method' => Payment::METHOD_MANUAL,
            'amount_minor' => 8000,
            'currency_code' => 'USD',
            'status' => Payment::STATUS_COMPLETED,
        ]);

        $this->assertTrue($payment1->isCompleted());
        $this->assertSame(8000, $payment1->getAllocatedAmountMinor());

        $freshInvoice = $this->invoiceService->findInvoiceById($invoice->getId());
        $this->assertSame(Invoice::STATUS_PARTIALLY_PAID, $freshInvoice->getStatus());
        $this->assertSame(8000, $freshInvoice->getPaidAmountMinor());
        $this->assertSame(12000, $freshInvoice->getBalanceDueMinor());

        // Order remains pending payment
        $freshOrder = $this->orderService->findOrderById($order->getId());
        $this->assertSame(OrderStateMachine::STATUS_PENDING_PAYMENT, $freshOrder->getStatus());

        // Split 2: Second payment of $120 (12000 minor) via bank transfer
        $payment2 = $this->paymentService->recordPayment([
            'user_id' => $userId,
            'invoice_id' => $invoice->getId(),
            'payment_method' => Payment::METHOD_BANK_TRANSFER,
            'amount_minor' => 12000,
            'currency_code' => 'USD',
        ]);
        $this->assertTrue($payment2->isPending());

        // Approve second payment
        $this->paymentService->approveManualPayment($payment2->getId());

        $finalInvoice = $this->invoiceService->findInvoiceById($invoice->getId());
        $this->assertSame(Invoice::STATUS_PAID, $finalInvoice->getStatus());
        $this->assertSame(20000, $finalInvoice->getPaidAmountMinor());
        $this->assertSame(0, $finalInvoice->getBalanceDueMinor());
        $this->assertTrue($finalInvoice->isPaid());

        // Order is now active
        $finalOrder = $this->orderService->findOrderById($order->getId());
        $this->assertSame(OrderStateMachine::STATUS_ACTIVE, $finalOrder->getStatus());

        // Verify allocations for invoice
        $payments = $this->paymentService->listPaymentsForInvoice($invoice->getId());
        $this->assertCount(2, $payments);
    }

    public function testPartialAndFullRefundWorkflow(): void
    {
        $userId = 200;
        $order = $this->orderService->createOrder(
            orderData: ['user_id' => $userId, 'currency_code' => 'USD'],
            itemsData: [['product_id' => 20, 'unit_price_minor' => 10000]]
        );
        $invoice = $this->invoiceService->createInvoiceFromOrder($order);

        $payment = $this->paymentService->recordPayment([
            'user_id' => $userId,
            'invoice_id' => $invoice->getId(),
            'payment_method' => Payment::METHOD_MANUAL,
            'amount_minor' => 10000,
            'currency_code' => 'USD',
            'status' => Payment::STATUS_COMPLETED,
        ]);

        $this->assertTrue($payment->isCompleted());
        $this->assertSame(10000, $payment->getRefundableAmountMinor());
        $this->assertSame(0, $payment->getRefundedAmountMinor());

        // Step 1: Issue partial refund of $30 (3000 minor)
        $refund1 = $this->paymentService->recordRefund([
            'payment_id' => $payment->getId(),
            'amount_minor' => 3000,
            'reason' => 'Partial goodwill credit for downtime',
        ]);

        $this->assertNotNull($refund1->getId());
        $this->assertStringStartsWith('REF-', $refund1->getRefundNumber());
        $this->assertSame(3000, $refund1->getAmountMinor());
        $this->assertSame($invoice->getId(), $refund1->getInvoiceId());

        // Check updated payment state
        $refreshedPayment = $this->paymentService->findPaymentById($payment->getId());
        $this->assertSame(Payment::STATUS_PARTIALLY_REFUNDED, $refreshedPayment->getStatus());
        $this->assertTrue($refreshedPayment->isPartiallyRefunded());
        $this->assertSame(3000, $refreshedPayment->getRefundedAmountMinor());
        $this->assertSame(7000, $refreshedPayment->getRefundableAmountMinor());

        // Check invoice adjusted state
        $adjustedInvoice = $this->invoiceService->findInvoiceById($invoice->getId());
        $this->assertSame(Invoice::STATUS_PARTIALLY_PAID, $adjustedInvoice->getStatus());
        $this->assertSame(7000, $adjustedInvoice->getPaidAmountMinor());
        $this->assertSame(3000, $adjustedInvoice->getBalanceDueMinor());

        // Step 2: Issue remaining refund of $70 (7000 minor)
        $refund2 = $this->paymentService->recordRefund([
            'payment_id' => $payment->getId(),
            'amount_minor' => 7000,
            'reason' => 'Full product cancellation refund',
        ]);

        $this->assertSame(7000, $refund2->getAmountMinor());

        // Payment fully refunded
        $finalPayment = $this->paymentService->findPaymentById($payment->getId());
        $this->assertSame(Payment::STATUS_REFUNDED, $finalPayment->getStatus());
        $this->assertTrue($finalPayment->isRefunded());
        $this->assertSame(10000, $finalPayment->getRefundedAmountMinor());
        $this->assertSame(0, $finalPayment->getRefundableAmountMinor());

        // Invoice status is refunded
        $finalInvoice = $this->invoiceService->findInvoiceById($invoice->getId());
        $this->assertSame(Invoice::STATUS_REFUNDED, $finalInvoice->getStatus());
        $this->assertSame(0, $finalInvoice->getPaidAmountMinor());

        // Attempting to refund any further amount throws ValidationException
        $this->expectException(ValidationException::class);
        $this->paymentService->recordRefund([
            'payment_id' => $payment->getId(),
            'amount_minor' => 100,
            'reason' => 'Impossible refund',
        ]);
    }

    public function testRefundValidationsAndListing(): void
    {
        $userId = 300;
        $payment = $this->paymentService->recordPayment([
            'user_id' => $userId,
            'payment_method' => Payment::METHOD_BANK_TRANSFER,
            'amount_minor' => 5000,
            'currency_code' => 'USD',
        ]);

        // 1. Pending payment cannot be refunded
        $this->expectException(ValidationException::class);
        $this->paymentService->recordRefund([
            'payment_id' => $payment->getId(),
            'amount_minor' => 1000,
            'reason' => 'Premature refund',
        ]);
    }

    public function testRefundListingQueries(): void
    {
        $userId = 400;
        $order = $this->orderService->createOrder(
            orderData: ['user_id' => $userId, 'currency_code' => 'USD'],
            itemsData: [['product_id' => 5, 'unit_price_minor' => 10000]]
        );
        $invoice = $this->invoiceService->createInvoiceFromOrder($order);

        $payment = $this->paymentService->recordPayment([
            'user_id' => $userId,
            'invoice_id' => $invoice->getId(),
            'payment_method' => Payment::METHOD_MANUAL,
            'amount_minor' => 10000,
            'currency_code' => 'USD',
            'status' => Payment::STATUS_COMPLETED,
        ]);

        $this->paymentService->recordRefund([
            'payment_id' => $payment->getId(),
            'amount_minor' => 2000,
            'reason' => 'Refund 1',
        ]);

        $this->paymentService->recordRefund([
            'payment_id' => $payment->getId(),
            'amount_minor' => 3000,
            'reason' => 'Refund 2',
        ]);

        $paymentRefunds = $this->paymentService->listRefundsForPayment($payment->getId());
        $this->assertCount(2, $paymentRefunds);

        $invoiceRefunds = $this->paymentService->listRefundsForInvoice($invoice->getId());
        $this->assertCount(2, $invoiceRefunds);

        $userRefunds = $this->paymentService->listRefundsForUser($userId);
        $this->assertCount(2, $userRefunds);
    }
}
