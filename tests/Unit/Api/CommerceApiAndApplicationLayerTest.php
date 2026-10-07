<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Api;

use Coleza\Api\Controllers\Commerce\InvoiceApiController;
use Coleza\Api\Controllers\Commerce\OrderApiController;
use Coleza\Api\Controllers\Commerce\PaymentApiController;
use Coleza\Api\Controllers\Commerce\QuoteApiController;
use Coleza\Application\Commerce\Invoices\InvoiceApplicationService;
use Coleza\Application\Commerce\Orders\OrderApplicationService;
use Coleza\Application\Commerce\Payments\PaymentApplicationService;
use Coleza\Application\Commerce\Quotes\QuoteApplicationService;
use Coleza\Domain\Commerce\Invoices\InvoiceService;
use Coleza\Domain\Commerce\Orders\OrderService;
use Coleza\Domain\Commerce\Orders\OrderStateMachine;
use Coleza\Domain\Commerce\Payments\Gateways\Iyzico\IyzicoConfiguration;
use Coleza\Domain\Commerce\Payments\Gateways\Iyzico\IyzicoPaymentGateway;
use Coleza\Domain\Commerce\Payments\PaymentService;
use Coleza\Domain\Documents\Quotes\QuoteService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\AuthorizationException;
use Coleza\Foundation\Exceptions\ResourceNotFoundException;
use PDO;
use PHPUnit\Framework\TestCase;

final class CommerceApiAndApplicationLayerTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private OrderService $orderService;
    private InvoiceService $invoiceService;
    private PaymentService $paymentService;
    private QuoteService $quoteService;

    private OrderApiController $orderController;
    private InvoiceApiController $invoiceController;
    private PaymentApiController $paymentController;
    private QuoteApiController $quoteController;
    private IyzicoPaymentGateway $iyzicoGateway;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db = new Connection($this->pdo, 'sqlite');

        $this->orderService = new OrderService($this->db);
        $this->orderService->ensureTables();

        $this->invoiceService = new InvoiceService($this->db, null, null, $this->orderService);
        $this->invoiceService->ensureTables();

        $this->paymentService = new PaymentService($this->db, $this->invoiceService, $this->orderService);
        $this->paymentService->ensureTables();

        $this->quoteService = new QuoteService($this->pdo, null, null, null);

        // Application Services
        $orderApp = new OrderApplicationService($this->orderService);
        $invoiceApp = new InvoiceApplicationService($this->invoiceService);
        $paymentApp = new PaymentApplicationService($this->paymentService, $this->invoiceService);
        $quoteApp = new QuoteApplicationService($this->quoteService, $this->orderService);

        // API Controllers
        $this->orderController = new OrderApiController($orderApp);
        $this->invoiceController = new InvoiceApiController($invoiceApp);
        $this->paymentController = new PaymentApiController($paymentApp);
        $this->quoteController = new QuoteApiController($quoteApp);

        $mockClient = function (): array {
            return [
                'code' => 200,
                'body' => json_encode([
                    'status' => 'success',
                    'token' => 'tok_api_test_123',
                    'checkoutFormContent' => '<script>checkout()</script>',
                    'paymentPageUrl' => 'https://sandbox-cpp.iyzipay.com/pay',
                ]),
            ];
        };

        $config = new IyzicoConfiguration('key', 'secret', 'https://sandbox-api.iyzipay.com');
        $this->iyzicoGateway = new IyzicoPaymentGateway($config, $mockClient);
    }

    public function testOrderApiControllerCreateShowListAndCancel(): void
    {
        $clientAuth = ['user_id' => 10, 'is_admin' => false];

        // 1. Create order
        $createRes = $this->orderController->create([
            'currency_code' => 'TRY',
            'items' => [
                [
                    'product_type' => 'shared_hosting',
                    'product_id' => 1,
                    'product_name' => 'Web Pro',
                    'billing_cycle' => 'monthly',
                    'currency_code' => 'TRY',
                    'price_minor' => 12000,
                ],
            ],
        ], $clientAuth);

        $this->assertSame('success', $createRes['status']);
        $this->assertSame(201, $createRes['code']);
        $orderId = (int) $createRes['data']['order_id'];
        $this->assertGreaterThan(0, $orderId);

        // 2. Show order
        $showRes = $this->orderController->show($orderId, $clientAuth);
        $this->assertSame('success', $showRes['status']);
        $this->assertSame(10, $showRes['data']['user_id']);
        $this->assertSame(OrderStateMachine::STATUS_PENDING_PAYMENT, $showRes['data']['status']);

        // 3. List orders
        $listRes = $this->orderController->list($clientAuth);
        $this->assertSame('success', $listRes['status']);
        $this->assertCount(1, $listRes['data']);

        // 4. Cancel order
        $cancelRes = $this->orderController->cancel($orderId, ['reason' => 'Changed mind'], $clientAuth);
        $this->assertSame('success', $cancelRes['status']);
        $this->assertSame(OrderStateMachine::STATUS_CANCELLED, $cancelRes['data']['status']);
    }

    public function testOrderApiControllerIdorProtection(): void
    {
        $aliceAuth = ['user_id' => 11, 'is_admin' => false];
        $bobAuth = ['user_id' => 12, 'is_admin' => false];

        // Alice creates an order
        $createRes = $this->orderController->create([
            'items' => [
                [
                    'product_type' => 'vps',
                    'product_id' => 2,
                    'product_name' => 'Cloud Server',
                    'billing_cycle' => 'monthly',
                    'currency_code' => 'TRY',
                    'price_minor' => 20000,
                ],
            ],
        ], $aliceAuth);

        $orderId = (int) $createRes['data']['order_id'];

        // Bob attempts to view Alice's order -> MUST throw AuthorizationException
        $this->expectException(AuthorizationException::class);
        $this->orderController->show($orderId, $bobAuth);
    }

    public function testInvoiceApiControllerCreateShowAndList(): void
    {
        $clientAuth = ['user_id' => 20, 'is_admin' => false];

        // 1. Create invoice
        $createRes = $this->invoiceController->create([
            'currency_code' => 'TRY',
            'items' => [
                ['description' => 'Managed Backup', 'unit_amount_minor' => 4500, 'tax_rate_percent' => 0.0],
            ],
        ], $clientAuth);

        $this->assertSame('success', $createRes['status']);
        $this->assertSame(201, $createRes['code']);
        $invoiceId = (int) $createRes['data']['invoice_id'];

        // 2. Show invoice
        $showRes = $this->invoiceController->show($invoiceId, $clientAuth);
        $this->assertSame('success', $showRes['status']);
        $this->assertSame(4500, $showRes['data']['total_minor']);

        // 3. List invoices
        $listRes = $this->invoiceController->list($clientAuth);
        $this->assertSame('success', $listRes['status']);
        $this->assertCount(1, $listRes['data']);

        // 4. IDOR attempt by another user
        $intruderAuth = ['user_id' => 99, 'is_admin' => false];
        $this->expectException(AuthorizationException::class);
        $this->invoiceController->show($invoiceId, $intruderAuth);
    }

    public function testPaymentApiControllerCheckoutAndManualPayment(): void
    {
        $clientAuth = ['user_id' => 30, 'is_admin' => false];

        // Create an invoice
        $invRes = $this->invoiceController->create([
            'currency_code' => 'TRY',
            'items' => [
                ['description' => 'Domain Registration', 'unit_amount_minor' => 3000, 'tax_rate_percent' => 0.0],
            ],
        ], $clientAuth);
        $invoiceId = (int) $invRes['data']['invoice_id'];

        // 1. Initiate Checkout via Gateway
        $checkoutRes = $this->paymentController->checkout([
            'invoice_id' => $invoiceId,
            'buyer_name' => 'Ahmet',
            'buyer_surname' => 'Yilmaz',
            'buyer_email' => 'ahmet@example.com',
            'buyer_ip' => '1.2.3.4',
            'callback_url' => 'https://example.com/callback',
        ], $this->iyzicoGateway, $clientAuth);

        $this->assertSame('success', $checkoutRes['status']);
        $this->assertTrue($checkoutRes['data']['success']);
        $this->assertSame('tok_api_test_123', $checkoutRes['data']['token']);

        // 2. Submit Manual Bank Transfer Payment
        $manualRes = $this->paymentController->submitManual([
            'invoice_id' => $invoiceId,
            'amount_minor' => 3000,
            'payment_method' => 'bank_transfer',
            'transaction_reference' => 'EFT-TR-123456',
            'proof_document_url' => 'https://cdn.example.com/proofs/123.jpg',
        ], $clientAuth);

        $this->assertSame('success', $manualRes['status']);
        $this->assertSame(201, $manualRes['code']);
        $paymentId = (int) $manualRes['data']['payment_id'];

        // 3. Show Payment
        $showRes = $this->paymentController->show($paymentId, $clientAuth);
        $this->assertSame('success', $showRes['status']);
        $this->assertSame(3000, $showRes['data']['amount_minor']);

        // 4. List Payments
        $listRes = $this->paymentController->list($clientAuth);
        $this->assertSame('success', $listRes['status']);
        // 2 payments (1 pending from checkout, 1 pending from manual EFT)
        $this->assertCount(2, $listRes['data']);
    }

    public function testPaymentApiControllerRefundAccessControl(): void
    {
        $clientAuth = ['user_id' => 40, 'is_admin' => false];
        $adminAuth = ['user_id' => 1, 'is_admin' => true];

        // Record a completed payment
        $payment = $this->paymentService->recordPayment([
            'user_id' => 40,
            'amount_minor' => 10000,
            'currency_code' => 'TRY',
            'payment_method' => 'credit',
            'status' => 'completed',
        ]);

        // Regular user attempts to issue refund -> MUST throw AuthorizationException
        try {
            $this->paymentController->refund(
                (int) $payment->getId(),
                ['amount_minor' => 5000, 'reason' => 'Customer request'],
                null,
                $clientAuth
            );
            $this->fail('Expected AuthorizationException for non-admin refund was not thrown.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('administrative staff', $e->getMessage());
        }

        // Admin issues refund -> SUT succeeds
        $adminRefundRes = $this->paymentController->refund(
            (int) $payment->getId(),
            ['amount_minor' => 5000, 'reason' => 'Customer approved refund'],
            null,
            $adminAuth
        );

        $this->assertSame('success', $adminRefundRes['status']);
        $this->assertSame(5000, $adminRefundRes['data']['amount_minor']);
    }

    public function testQuoteApiControllerShowAndAccept(): void
    {
        $clientAuth = ['user_id' => 50, 'is_admin' => false];

        // Create a quote in domain
        $quote = $this->quoteService->createQuote(
            userId: 50,
            itemsData: [
                ['description' => 'Custom Dedicated Cluster', 'unit_price' => 50000.00, 'quantity' => 1],
            ],
            currencyCode: 'TRY',
            validUntil: '+14 days'
        );

        // 1. Show Quote via API
        $showRes = $this->quoteController->show((int) $quote->getId(), $clientAuth);
        $this->assertSame('success', $showRes['status']);
        $this->assertSame(50, $showRes['data']['user_id']);

        // 2. Accept Quote and convert to order
        $acceptRes = $this->quoteController->accept(
            (int) $quote->getId(),
            ['ip' => '192.168.1.50'],
            $clientAuth
        );

        $this->assertSame('success', $acceptRes['status']);
        $this->assertSame(201, $acceptRes['code']);
        $this->assertGreaterThan(0, $acceptRes['data']['order_id']);

        // 3. IDOR test: another client cannot view this quote
        $otherClientAuth = ['user_id' => 51, 'is_admin' => false];
        $this->expectException(AuthorizationException::class);
        $this->quoteController->show((int) $quote->getId(), $otherClientAuth);
    }
}
