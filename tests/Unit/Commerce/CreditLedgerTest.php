<?php

declare(strict_types=1);

namespace Tests\Unit\Commerce;

use Coleza\Domain\Commerce\Credit\CreditEntry;
use Coleza\Domain\Commerce\Credit\CreditService;
use Coleza\Domain\Commerce\Invoices\Invoice;
use Coleza\Domain\Commerce\Invoices\InvoiceService;
use Coleza\Domain\Commerce\Orders\OrderService;
use Coleza\Domain\Commerce\Orders\OrderStateMachine;
use Coleza\Domain\Commerce\Payments\Payment;
use Coleza\Domain\Commerce\Payments\PaymentService;
use Coleza\Domain\Pricing\Services\PricingService;
use Coleza\Domain\Tax\Services\TaxService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class CreditLedgerTest extends TestCase
{
    private Connection $db;
    private PricingService $pricingService;
    private TaxService $taxService;
    private OrderService $orderService;
    private InvoiceService $invoiceService;
    private PaymentService $paymentService;
    private CreditService $creditService;

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

        $this->creditService = new CreditService($this->db, $this->invoiceService, $this->paymentService);
        $this->creditService->ensureTables();
    }

    public function testCreditDepositAndDeductionWithBalanceTracking(): void
    {
        $userId = 1;
        $this->assertSame(0, $this->creditService->getBalance($userId, 'USD'));

        // 1. Deposit $100
        $entry1 = $this->creditService->addCredit(
            userId: $userId,
            amountMinor: 10000,
            currencyCode: 'USD',
            reason: 'Account recharge'
        );

        $this->assertNotNull($entry1->getId());
        $this->assertStringStartsWith('CR-', $entry1->getEntryNumber());
        $this->assertTrue($entry1->isCredit());
        $this->assertSame(10000, $entry1->getAmountMinor());
        $this->assertSame(10000, $entry1->getBalanceAfterMinor());
        $this->assertSame(10000, $this->creditService->getBalance($userId, 'USD'));

        // 2. Deposit $50
        $entry2 = $this->creditService->addCredit(
            userId: $userId,
            amountMinor: 5000,
            currencyCode: 'USD',
            reason: 'Loyalty bonus credit'
        );
        $this->assertSame(15000, $entry2->getBalanceAfterMinor());
        $this->assertSame(15000, $this->creditService->getBalance($userId, 'USD'));

        // 3. Deduct $40
        $entry3 = $this->creditService->deductCredit(
            userId: $userId,
            amountMinor: 4000,
            currencyCode: 'USD',
            reason: 'Service usage deduction'
        );
        $this->assertTrue($entry3->isDebit());
        $this->assertSame(4000, $entry3->getAmountMinor());
        $this->assertSame(11000, $entry3->getBalanceAfterMinor());
        $this->assertSame(11000, $this->creditService->getBalance($userId, 'USD'));

        // 4. Overdrawing credit throws ValidationException
        $this->expectException(ValidationException::class);
        $this->creditService->deductCredit(
            userId: $userId,
            amountMinor: 12000,
            currencyCode: 'USD',
            reason: 'Excessive deduction'
        );
    }

    public function testApplyCreditToInvoice(): void
    {
        $userId = 5;
        // User has $100 credit
        $this->creditService->addCredit($userId, 10000, 'USD', 'Deposit');

        // Create order of $75 (7500 minor)
        $order = $this->orderService->createOrder(
            orderData: ['user_id' => $userId, 'currency_code' => 'USD'],
            itemsData: [['product_id' => 10, 'unit_price_minor' => 7500]]
        );
        $invoice = $this->invoiceService->createInvoiceFromOrder($order);

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->getStatus());

        // Apply $75 credit to invoice
        $debitEntry = $this->creditService->applyCreditToInvoice($userId, $invoice->getId(), 7500);

        $this->assertSame(CreditEntry::REF_INVOICE, $debitEntry->getReferenceType());
        $this->assertSame($invoice->getId(), $debitEntry->getReferenceId());
        $this->assertSame(2500, $this->creditService->getBalance($userId, 'USD'));

        // Verify invoice is marked paid
        $updatedInvoice = $this->invoiceService->findInvoiceById($invoice->getId());
        $this->assertSame(Invoice::STATUS_PAID, $updatedInvoice->getStatus());
        $this->assertSame(7500, $updatedInvoice->getPaidAmountMinor());
        $this->assertSame(0, $updatedInvoice->getBalanceDueMinor());

        // Verify order is activated
        $updatedOrder = $this->orderService->findOrderById($order->getId());
        $this->assertSame(OrderStateMachine::STATUS_ACTIVE, $updatedOrder->getStatus());
    }

    public function testRefundToCredit(): void
    {
        $userId = 12;
        $order = $this->orderService->createOrder(
            orderData: ['user_id' => $userId, 'currency_code' => 'USD'],
            itemsData: [['product_id' => 1, 'unit_price_minor' => 6000]]
        );
        $invoice = $this->invoiceService->createInvoiceFromOrder($order);

        $payment = $this->paymentService->recordPayment([
            'user_id' => $userId,
            'invoice_id' => $invoice->getId(),
            'payment_method' => Payment::METHOD_BANK_TRANSFER,
            'amount_minor' => 6000,
            'currency_code' => 'USD',
            'status' => Payment::STATUS_COMPLETED,
        ]);

        $this->assertSame(0, $this->creditService->getBalance($userId, 'USD'));

        // Refund $60 back into user credit
        $creditEntry = $this->creditService->refundToCredit(
            paymentId: $payment->getId(),
            amountMinor: 6000,
            reason: 'Customer requested refund as store credit'
        );

        $this->assertTrue($creditEntry->isCredit());
        $this->assertSame(6000, $creditEntry->getAmountMinor());
        $this->assertSame(CreditEntry::REF_REFUND, $creditEntry->getReferenceType());
        $this->assertSame(6000, $this->creditService->getBalance($userId, 'USD'));

        // Verify payment is marked refunded
        $refreshedPayment = $this->paymentService->findPaymentById($payment->getId());
        $this->assertTrue($refreshedPayment->isRefunded());
    }

    public function testAdminManualAdjustment(): void
    {
        $userId = 25;
        // Adjust balance from 0 to 12000 minor ($120)
        $adj1 = $this->creditService->adjustBalance($userId, 12000, 'USD', 'Initial balance adjustment', 999);
        $this->assertSame(12000, $adj1->getBalanceAfterMinor());
        $this->assertSame(12000, $this->creditService->getBalance($userId, 'USD'));

        // Adjust balance down to 5000 minor ($50)
        $adj2 = $this->creditService->adjustBalance($userId, 5000, 'USD', 'Correction of over-crediting', 999);
        $this->assertSame(5000, $adj2->getBalanceAfterMinor());
        $this->assertSame(5000, $this->creditService->getBalance($userId, 'USD'));

        $entries = $this->creditService->listEntriesForUser($userId, 'USD');
        $this->assertCount(2, $entries);
    }

    public function testMultiCurrencyAndMultiTenantIsolation(): void
    {
        $userId = 42;
        $orgId = 88;

        // Personal USD
        $this->creditService->addCredit($userId, 5000, 'USD', 'USD deposit');
        // Personal EUR
        $this->creditService->addCredit($userId, 3000, 'EUR', 'EUR deposit');
        // Org-scoped USD
        $this->creditService->addCredit($userId, 9000, 'USD', 'Org USD deposit', orgId: $orgId);

        $this->assertSame(5000, $this->creditService->getBalance($userId, 'USD'));
        $this->assertSame(3000, $this->creditService->getBalance($userId, 'EUR'));
        $this->assertSame(9000, $this->creditService->getBalance($userId, 'USD', $orgId));
        $this->assertSame(0, $this->creditService->getBalance($userId, 'EUR', $orgId));
    }
}
