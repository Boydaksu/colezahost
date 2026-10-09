<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Payments;

use Coleza\Domain\Commerce\Invoices\InvoiceService;
use Coleza\Domain\Commerce\Orders\OrderService;
use Coleza\Domain\Commerce\Orders\OrderStateMachine;
use Coleza\Domain\Commerce\Payments\Exceptions\PaymentRefundFailedException;
use Coleza\Domain\Commerce\Payments\Gateways\Iyzico\IyzicoConfiguration;
use Coleza\Domain\Commerce\Payments\Gateways\Iyzico\IyzicoPaymentGateway;
use Coleza\Domain\Commerce\Payments\Gateways\PaymentWebhookHandler;
use Coleza\Domain\Commerce\Payments\Payment;
use Coleza\Domain\Commerce\Payments\PaymentService;
use Coleza\Domain\Commerce\Payments\Refund;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

final class PaymentWebhookAndRefundTest extends TestCase
{
    private Connection $db;
    private InvoiceService $invoiceService;
    private OrderService $orderService;
    private PaymentService $paymentService;
    private PaymentWebhookHandler $webhookHandler;
    private IyzicoConfiguration $iyzicoConfig;

    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db = new Connection($pdo, 'sqlite');

        $this->orderService = new OrderService($this->db);
        $this->orderService->ensureTables();

        $this->invoiceService = new InvoiceService($this->db, null, null, $this->orderService);
        $this->invoiceService->ensureTables();

        $this->paymentService = new PaymentService($this->db, $this->invoiceService, $this->orderService);
        $this->paymentService->ensureTables();

        $this->webhookHandler = new PaymentWebhookHandler(
            $this->db,
            $this->paymentService,
            $this->invoiceService,
            $this->orderService
        );

        $this->iyzicoConfig = new IyzicoConfiguration(
            apiKey: 'test-api-key',
            secretKey: 'test-secret-key',
            baseUrl: 'https://sandbox-api.iyzipay.com',
            isTestMode: true
        );
    }

    public function testSuccessfulIyzicoCallbackSettlesPendingPaymentAndAllocatesInvoice(): void
    {
        // 1. Create order
        $order = $this->orderService->createOrder(
            ['user_id' => 1, 'currency_code' => 'TRY'],
            [
                [
                    'product_type' => 'shared_hosting',
                    'product_id' => 1,
                    'product_name' => 'Starter Hosting',
                    'billing_cycle' => 'monthly',
                    'currency_code' => 'TRY',
                    'price_minor' => 10000,
                ],
            ]
        );

        // 2. Create invoice linked to order
        $invoice = $this->invoiceService->createInvoice(
            [
                'order_id' => $order->getId(),
                'user_id' => 1,
                'currency_code' => 'TRY',
            ],
            [
                [
                    'description' => 'Starter Hosting Plan',
                    'unit_amount_minor' => 10000,
                    'tax_rate_percent' => 0.0,
                ],
            ]
        );

        // 3. Record pending gateway payment
        $payment = $this->paymentService->recordPayment([
            'user_id' => 1,
            'invoice_id' => $invoice->getId(),
            'amount_minor' => 10000,
            'currency_code' => 'TRY',
            'payment_method' => 'iyzico',
            'status' => Payment::STATUS_PENDING,
            'metadata' => [
                'checkout_token' => 'token_success_abc123',
            ],
        ]);

        $this->assertSame(Payment::STATUS_PENDING, $payment->getStatus());
        $this->assertSame(10000, $invoice->getBalanceDueMinor());
        $this->assertSame(OrderStateMachine::STATUS_PENDING_PAYMENT, $order->getStatus());

        // 4. Mock iyzico gateway responding with successful verification
        $mockHttpClient = function (string $url, string $payload, array $headers) use ($payment, $invoice): array {
            return [
                'code' => 200,
                'body' => json_encode([
                    'status' => 'success',
                    'paymentStatus' => 'SUCCESS',
                    'paymentId' => 'iyz_pay_777888',
                    'conversationId' => $payment->getPaymentNumber(),
                    'paidPrice' => '100.00',
                    'currency' => 'TRY',
                    'iyziCommissionRateAmount' => '2.50',
                    'iyziCommissionFee' => '0.25',
                    'cardAssociation' => 'VISA',
                    'cardFamily' => 'World',
                    'installment' => 1,
                    'itemTransactions' => [
                        [
                            'paymentTransactionId' => 'txn_item_999000',
                            'itemId' => 'inv_' . $invoice->getId(),
                            'paidPrice' => '100.00',
                        ],
                    ],
                ]),
            ];
        };

        $gateway = new IyzicoPaymentGateway($this->iyzicoConfig, $mockHttpClient);

        // 5. Handle callback
        $callbackPayload = [
            'token' => 'token_success_abc123',
            'conversationId' => $payment->getPaymentNumber(),
        ];

        $result = $this->webhookHandler->handleIyzicoCallback($gateway, $callbackPayload);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->isProcessed());
        $this->assertSame($payment->getId(), $result->getPaymentId());

        // 6. Verify Payment is now COMPLETED
        $updatedPayment = $this->paymentService->findPaymentById((int) $payment->getId());
        $this->assertNotNull($updatedPayment);
        $this->assertSame(Payment::STATUS_COMPLETED, $updatedPayment->getStatus());
        $this->assertSame('txn_item_999000', $updatedPayment->getTransactionReference());
        $this->assertSame(275, $updatedPayment->getFeeMinor());
        $this->assertSame(9725, $updatedPayment->getNetAmountMinor());
        $this->assertNotNull($updatedPayment->getPaidAt());

        // 7. Verify Invoice is now fully PAID
        $updatedInvoice = $this->invoiceService->findInvoiceById((int) $invoice->getId());
        $this->assertNotNull($updatedInvoice);
        $this->assertTrue($updatedInvoice->isPaid());
        $this->assertSame(0, $updatedInvoice->getBalanceDueMinor());

        // 8. Verify Order is now ACTIVE
        $updatedOrder = $this->orderService->findOrderById((int) $order->getId());
        $this->assertNotNull($updatedOrder);
        $this->assertSame(OrderStateMachine::STATUS_ACTIVE, $updatedOrder->getStatus());
    }

    public function testDuplicateCallbackIsIdempotentAndDoesNotDoubleCredit(): void
    {
        // Create invoice and pending payment
        $invoice = $this->invoiceService->createInvoice(
            [
                'user_id' => 2,
                'currency_code' => 'TRY',
            ],
            [
                [
                    'description' => 'VPS Plan Alpha',
                    'unit_amount_minor' => 25000,
                    'tax_rate_percent' => 0.0,
                ],
            ]
        );

        $payment = $this->paymentService->recordPayment([
            'user_id' => 2,
            'invoice_id' => $invoice->getId(),
            'amount_minor' => 25000,
            'currency_code' => 'TRY',
            'payment_method' => 'iyzico',
            'status' => Payment::STATUS_PENDING,
            'metadata' => ['checkout_token' => 'token_idempotent_test_999'],
        ]);

        $mockHttpClient = function () use ($payment): array {
            return [
                'code' => 200,
                'body' => json_encode([
                    'status' => 'success',
                    'paymentStatus' => 'SUCCESS',
                    'paymentId' => 'iyz_pay_111',
                    'conversationId' => $payment->getPaymentNumber(),
                    'paidPrice' => '250.00',
                    'currency' => 'TRY',
                    'iyziCommissionRateAmount' => '5.00',
                    'iyziCommissionFee' => '0.50',
                ]),
            ];
        };

        $gateway = new IyzicoPaymentGateway($this->iyzicoConfig, $mockHttpClient);

        $payload = [
            'token' => 'token_idempotent_test_999',
            'conversationId' => $payment->getPaymentNumber(),
        ];

        // First callback execution -> PROCESSED
        $res1 = $this->webhookHandler->handleIyzicoCallback($gateway, $payload);
        $this->assertTrue($res1->isSuccess());
        $this->assertTrue($res1->isProcessed());

        // Check allocation count
        $allocations1 = $this->paymentService->findPaymentById((int) $payment->getId())->getAllocations();
        $this->assertCount(1, $allocations1);
        $this->assertSame(25000, $allocations1[0]->getAmountMinor());

        // Second identical callback execution (replay / retry) -> DUPLICATE
        $res2 = $this->webhookHandler->handleIyzicoCallback($gateway, $payload);
        $this->assertTrue($res2->isSuccess());
        $this->assertTrue($res2->isDuplicate());
        $this->assertFalse($res2->isProcessed());
        $this->assertSame('Webhook/callback event already processed successfully (idempotent duplicate).', $res2->getMessage());

        // Assert no double allocation or duplicate ledger balance
        $allocations2 = $this->paymentService->findPaymentById((int) $payment->getId())->getAllocations();
        $this->assertCount(1, $allocations2);

        $invUpdated = $this->invoiceService->findInvoiceById((int) $invoice->getId());
        $this->assertSame(0, $invUpdated->getBalanceDueMinor());
    }

    public function testCallbackWithVerificationFailureMarksPaymentFailed(): void
    {
        $invoice = $this->invoiceService->createInvoice(
            [
                'user_id' => 3,
                'currency_code' => 'TRY',
            ],
            [
                ['description' => 'SSL Certificate', 'unit_amount_minor' => 5000, 'tax_rate_percent' => 0.0],
            ]
        );

        $payment = $this->paymentService->recordPayment([
            'user_id' => 3,
            'invoice_id' => $invoice->getId(),
            'amount_minor' => 5000,
            'currency_code' => 'TRY',
            'payment_method' => 'iyzico',
            'status' => Payment::STATUS_PENDING,
            'metadata' => ['checkout_token' => 'token_fail_3d'],
        ]);

        // Mock gateway returning verification failure
        $mockHttpClient = function (): array {
            return [
                'code' => 200,
                'body' => json_encode([
                    'status' => 'failure',
                    'errorMessage' => '3D Secure authentication failed: Cardholder cancelled.',
                ]),
            ];
        };

        $gateway = new IyzicoPaymentGateway($this->iyzicoConfig, $mockHttpClient);

        $payload = [
            'token' => 'token_fail_3d',
            'conversationId' => $payment->getPaymentNumber(),
        ];

        $result = $this->webhookHandler->handleIyzicoCallback($gateway, $payload);

        $this->assertFalse($result->isSuccess());
        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('3D Secure authentication failed', $result->getMessage());

        // Payment status must be FAILED
        $updatedPayment = $this->paymentService->findPaymentById((int) $payment->getId());
        $this->assertSame(Payment::STATUS_FAILED, $updatedPayment->getStatus());

        // Invoice balance due must remain unpaid
        $updatedInvoice = $this->invoiceService->findInvoiceById((int) $invoice->getId());
        $this->assertSame(5000, $updatedInvoice->getBalanceDueMinor());
        $this->assertFalse($updatedInvoice->isPaid());
    }

    public function testOutOfOrderCallbackOnAlreadyCompletedPayment(): void
    {
        // Create an already completed payment
        $payment = $this->paymentService->recordPayment([
            'user_id' => 4,
            'amount_minor' => 15000,
            'currency_code' => 'TRY',
            'payment_method' => 'iyzico',
            'status' => Payment::STATUS_COMPLETED,
            'transaction_reference' => 'pre_existing_tx_123',
            'metadata' => ['checkout_token' => 'token_late_webhook'],
        ]);

        $mockHttpClient = function () use ($payment): array {
            return [
                'code' => 200,
                'body' => json_encode([
                    'status' => 'success',
                    'paymentStatus' => 'SUCCESS',
                    'paymentId' => 'iyz_pay_out_of_order',
                    'conversationId' => $payment->getPaymentNumber(),
                    'paidPrice' => '150.00',
                    'currency' => 'TRY',
                ]),
            ];
        };

        $gateway = new IyzicoPaymentGateway($this->iyzicoConfig, $mockHttpClient);

        $payload = [
            'token' => 'token_late_webhook',
            'conversationId' => $payment->getPaymentNumber(),
        ];

        $result = $this->webhookHandler->handleIyzicoCallback($gateway, $payload);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('already_completed', $result->getStatus());
        $this->assertStringContainsString('already completed prior to this callback', $result->getMessage());
    }

    public function testGatewayRefundSuccessUpdatesPaymentAndInvoice(): void
    {
        // 1. Create invoice & completed payment
        $invoice = $this->invoiceService->createInvoice(
            [
                'user_id' => 5,
                'currency_code' => 'TRY',
            ],
            [
                ['description' => 'Dedicated IP', 'unit_amount_minor' => 6000, 'tax_rate_percent' => 0.0],
            ]
        );

        $payment = $this->paymentService->recordPayment([
            'user_id' => 5,
            'invoice_id' => $invoice->getId(),
            'amount_minor' => 6000,
            'currency_code' => 'TRY',
            'payment_method' => 'iyzico',
            'status' => Payment::STATUS_COMPLETED,
            'transaction_reference' => 'tx_refund_success_001',
        ]);

        $this->assertSame(6000, $payment->getRefundableAmountMinor());

        // 2. Mock gateway returning refund success
        $mockHttpClient = function (): array {
            return [
                'code' => 200,
                'body' => json_encode([
                    'status' => 'success',
                    'paymentId' => 'ref_tx_998877',
                    'price' => '30.00',
                ]),
            ];
        };

        $gateway = new IyzicoPaymentGateway($this->iyzicoConfig, $mockHttpClient);

        // 3. Partial refund of 30.00 TRY (3000 minor)
        $refund = $this->paymentService->refundViaGateway(
            paymentId: (int) $payment->getId(),
            amountMinor: 3000,
            reason: 'Partial cancellation of add-on',
            gateway: $gateway
        );

        $this->assertInstanceOf(Refund::class, $refund);
        $this->assertSame(3000, $refund->getAmountMinor());
        $this->assertSame('ref_tx_998877', $refund->getTransactionReference());
        $this->assertSame('iyzico', $refund->getRefundMethod());

        // 4. Verify payment state
        $updatedPayment = $this->paymentService->findPaymentById((int) $payment->getId());
        $this->assertSame(Payment::STATUS_PARTIALLY_REFUNDED, $updatedPayment->getStatus());
        $this->assertSame(3000, $updatedPayment->getRefundedAmountMinor());
        $this->assertSame(3000, $updatedPayment->getRefundableAmountMinor());

        // 5. Verify refund attempt was logged as SUCCESS
        $attempts = $this->paymentService->listRefundAttemptsForPayment((int) $payment->getId());
        $this->assertCount(1, $attempts);
        $this->assertTrue($attempts[0]->isSuccess());
        $this->assertSame('ref_tx_998877', $attempts[0]->getTransactionReference());
    }

    public function testGatewayRefundFailurePreservesPaymentAndRefundableBalance(): void
    {
        // 1. Create completed payment
        $payment = $this->paymentService->recordPayment([
            'user_id' => 6,
            'amount_minor' => 8000,
            'currency_code' => 'TRY',
            'payment_method' => 'iyzico',
            'status' => Payment::STATUS_COMPLETED,
            'transaction_reference' => 'tx_refund_fail_002',
        ]);

        // 2. Mock gateway returning refund failure
        $mockHttpClient = function (): array {
            return [
                'code' => 200,
                'body' => json_encode([
                    'status' => 'failure',
                    'errorCode' => 'ERR_REFUND_LIMIT_EXCEEDED',
                    'errorMessage' => 'Bank declined refund: Merchant refund account limit exceeded.',
                ]),
            ];
        };

        $gateway = new IyzicoPaymentGateway($this->iyzicoConfig, $mockHttpClient);

        // 3. Attempt refund -> MUST throw PaymentRefundFailedException
        try {
            $this->paymentService->refundViaGateway(
                paymentId: (int) $payment->getId(),
                amountMinor: 4000,
                reason: 'Customer downgrade',
                gateway: $gateway
            );
            $this->fail('Expected PaymentRefundFailedException was not thrown.');
        } catch (PaymentRefundFailedException $e) {
            $this->assertSame($payment->getId(), $e->getPaymentId());
            $this->assertSame(4000, $e->getAmountMinor());
            $this->assertSame('ERR_REFUND_LIMIT_EXCEEDED', $e->getErrorCode());
            $this->assertStringContainsString('Bank declined refund', $e->getMessage());
        }

        // 4. Invariant Assertion: Payment status remains COMPLETED, NOT refunded
        $preservedPayment = $this->paymentService->findPaymentById((int) $payment->getId());
        $this->assertSame(Payment::STATUS_COMPLETED, $preservedPayment->getStatus());
        $this->assertSame(0, $preservedPayment->getRefundedAmountMinor());
        $this->assertSame(8000, $preservedPayment->getRefundableAmountMinor());

        // 5. Invariant Assertion: Failed attempt is recorded in audit ledger
        $attempts = $this->paymentService->listRefundAttemptsForPayment((int) $payment->getId());
        $this->assertCount(1, $attempts);
        $this->assertTrue($attempts[0]->isFailed());
        $this->assertSame('ERR_REFUND_LIMIT_EXCEEDED', $attempts[0]->getErrorCode());
        $this->assertStringContainsString('Bank declined refund', (string) $attempts[0]->getErrorMessage());
    }

    public function testRefundValidationGuards(): void
    {
        // 1. Pending payment cannot be refunded
        $pendingPayment = $this->paymentService->recordPayment([
            'user_id' => 7,
            'amount_minor' => 1000,
            'currency_code' => 'TRY',
            'payment_method' => 'iyzico',
            'status' => Payment::STATUS_PENDING,
        ]);

        $gateway = new IyzicoPaymentGateway($this->iyzicoConfig);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid payment status for refund');

        $this->paymentService->refundViaGateway(
            paymentId: (int) $pendingPayment->getId(),
            amountMinor: 500,
            reason: 'Test invalid status',
            gateway: $gateway
        );
    }

    public function testRefundAmountExceedingBalanceThrowsValidationException(): void
    {
        $completedPayment = $this->paymentService->recordPayment([
            'user_id' => 8,
            'amount_minor' => 2000,
            'currency_code' => 'TRY',
            'payment_method' => 'iyzico',
            'status' => Payment::STATUS_COMPLETED,
            'transaction_reference' => 'tx_ref_guard_123',
        ]);

        $gateway = new IyzicoPaymentGateway($this->iyzicoConfig);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Excessive refund amount');

        $this->paymentService->refundViaGateway(
            paymentId: (int) $completedPayment->getId(),
            amountMinor: 3000,
            reason: 'Excessive refund test',
            gateway: $gateway
        );
    }
}
