<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Payments;

use Coleza\Application\Commerce\Payments\PaymentApplicationService;
use Coleza\Application\Commerce\Payments\RecordManualPaymentCommand;
use Coleza\Application\Commerce\Payments\InitiateCheckoutCommand;
use Coleza\Domain\Commerce\Invoices\InvoiceService;
use Coleza\Domain\Commerce\Payments\Payment;
use Coleza\Domain\Commerce\Payments\PaymentService;
use Coleza\Domain\Commerce\Payments\Gateways\PaymentWebhookHandler;
use Coleza\Domain\Commerce\Payments\Gateways\Iyzico\IyzicoConfiguration;
use Coleza\Domain\Commerce\Payments\Gateways\Iyzico\IyzicoPaymentGateway;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\AuthorizationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaymentSecurityRegressionTest extends TestCase
{
    private Connection $db;
    private InvoiceService $invoices;
    private PaymentService $payments;
    private int $invoiceId;

    protected function setUp(): void
    {
        $this->db = new Connection(new \PDO('sqlite::memory:'));
        $this->invoices = new InvoiceService($this->db);
        $this->invoices->ensureTables();
        $this->payments = new PaymentService($this->db, $this->invoices);
        $this->payments->ensureTables();
        $this->invoiceId = (int) $this->invoices->createInvoice(
            ['user_id' => 7, 'currency_code' => 'TRY'],
            [['description' => 'Hosting', 'unit_amount_minor' => 10000]]
        )->getId();
    }

    public function testClientManualSubmissionNeverSettlesInvoice(): void
    {
        $app = new PaymentApplicationService($this->payments, $this->invoices);
        $payment = $app->recordManualPayment(new RecordManualPaymentCommand(
            7, $this->invoiceId, 10000, paymentMethod: 'manual'
        ), 7);
        self::assertSame(Payment::STATUS_PENDING, $payment->getStatus());
        self::assertSame(10000, $this->invoices->findInvoiceById($this->invoiceId)->getBalanceDueMinor());
        self::assertCount(0, $payment->getAllocations());
    }

    public function testMissingAuthenticationCannotSubmitPayment(): void
    {
        $this->expectException(AuthorizationException::class);
        (new PaymentApplicationService($this->payments, $this->invoices))->recordManualPayment(
            new RecordManualPaymentCommand(7, $this->invoiceId, 10000)
        );
    }

    public function testSubmissionCannotAssignInvoiceToDifferentUser(): void
    {
        $this->expectException(AuthorizationException::class);
        (new PaymentApplicationService($this->payments, $this->invoices))->recordManualPayment(
            new RecordManualPaymentCommand(99, $this->invoiceId, 10000), 7
        );
    }

    public static function invalidVerification(): array
    {
        return [
            'underpayment' => ['0.01', 'TRY', 'correct-token', 'iyzico', false],
            'overpayment' => ['101.00', 'TRY', 'correct-token', 'iyzico', false],
            'currency' => ['100.00', 'USD', 'correct-token', 'iyzico', false],
            'partial token' => ['100.00', 'TRY', 'token', 'iyzico', false],
            'SQL wildcard token' => ['100.00', 'TRY', '%', 'iyzico', false],
            'wrong gateway' => ['100.00', 'TRY', 'correct-token', 'bank_transfer', false],
            'wrong verified number' => ['100.00', 'TRY', 'correct-token', 'iyzico', true],
            'missing verified number' => ['100.00', 'TRY', 'correct-token', 'iyzico', null],
        ];
    }

    #[DataProvider('invalidVerification')]
    public function testInvalidVerificationCannotCreditInvoice(string $amount, string $currency, string $token, string $method, ?bool $wrongNumber): void
    {
        $payment = $this->payments->recordPayment([
            'user_id' => 7, 'invoice_id' => $this->invoiceId, 'amount_minor' => 10000,
            'currency_code' => 'TRY', 'payment_method' => $method,
            'status' => Payment::STATUS_PENDING, 'metadata' => ['checkout_token' => 'correct-token'],
        ]);
        $gateway = new IyzicoPaymentGateway(new IyzicoConfiguration('key', 'secret'),
            static fn (): array => ['code' => 200, 'body' => json_encode([
                'status' => 'success', 'paymentStatus' => 'SUCCESS', 'paymentId' => 'provider-1',
                'conversationId' => $wrongNumber === null ? null : ($wrongNumber ? 'another-payment' : $payment->getPaymentNumber()),
                'paidPrice' => $amount, 'currency' => $currency,
            ])]);
        $result = (new PaymentWebhookHandler($this->db, $this->payments))->handleIyzicoCallback(
            $gateway, ['token' => $token, 'conversationId' => $payment->getPaymentNumber()]
        );
        self::assertFalse($result->isSuccess());
        self::assertSame(Payment::STATUS_PENDING, $this->payments->findPaymentById((int) $payment->getId())->getStatus());
        self::assertSame(10000, $this->invoices->findInvoiceById($this->invoiceId)->getBalanceDueMinor());
        self::assertCount(0, $this->payments->findPaymentById((int) $payment->getId())->getAllocations());
    }

    public function testTokenCannotBeMatchedInsideUnrelatedMetadata(): void
    {
        $this->payments->recordPayment(['user_id' => 7, 'amount_minor' => 10000,
            'currency_code' => 'TRY', 'status' => Payment::STATUS_PENDING,
            'metadata' => ['notes' => 'correct-token']]);
        self::assertNull($this->payments->findPaymentByToken('correct-token'));
    }

    public function testCheckoutUsesOnePaymentNumberAndVerifiedCallbackSettlesOnce(): void
    {
        $number = null;
        $gateway = new IyzicoPaymentGateway(new IyzicoConfiguration('key', 'secret'),
            static function (string $url, string $payload) use (&$number): array {
                $request = json_decode($payload, true);
                if (!isset($request['token'])) {
                    $number = $request['conversationId'];
                    return ['code' => 200, 'body' => json_encode(['status' => 'success', 'token' => 'checkout-1'])];
                }
                return ['code' => 200, 'body' => json_encode([
                    'status' => 'success', 'paymentStatus' => 'SUCCESS', 'paymentId' => 'provider-1',
                    'conversationId' => $number, 'paidPrice' => '100.00', 'currency' => 'TRY',
                ])];
            });
        (new PaymentApplicationService($this->payments, $this->invoices))->initiateCheckout($gateway,
            new InitiateCheckoutCommand($this->invoiceId, 7, 'Test', 'User', 'test@example.test', '127.0.0.1', 'https://example.test/callback'), 7);
        $payment = $this->payments->findPaymentByToken('checkout-1');
        self::assertNotNull($payment);
        self::assertSame($number, $payment->getPaymentNumber());
        self::assertCount(1, $this->payments->listPaymentsForUser(7));
        $handler = new PaymentWebhookHandler($this->db, $this->payments);
        self::assertTrue($handler->handleIyzicoCallback($gateway, ['token' => 'checkout-1'])->isProcessed());
        self::assertTrue($handler->handleIyzicoCallback($gateway, ['token' => 'checkout-1'])->isDuplicate());
        self::assertSame(0, $this->invoices->findInvoiceById($this->invoiceId)->getBalanceDueMinor());
        self::assertCount(1, $this->payments->findPaymentById((int) $payment->getId())->getAllocations());
    }

    public function testUnverifiedGenericPayloadCannotSettlePayment(): void
    {
        $payment = $this->payments->recordPayment(['user_id' => 7, 'invoice_id' => $this->invoiceId,
            'amount_minor' => 10000, 'currency_code' => 'TRY', 'payment_method' => 'iyzico', 'status' => Payment::STATUS_PENDING]);
        $gateway = new IyzicoPaymentGateway(new IyzicoConfiguration('key', 'secret'),
            static function (): never { throw new \LogicException('No token means no provider request.'); });
        $result = (new PaymentWebhookHandler($this->db, $this->payments))->handleGenericWebhook(
            $gateway, 'forged-event', 'payment.success', ['payment_number' => $payment->getPaymentNumber()]);
        self::assertFalse($result->isSuccess());
        self::assertSame(Payment::STATUS_PENDING, $this->payments->findPaymentById((int) $payment->getId())->getStatus());
        self::assertSame(10000, $this->invoices->findInvoiceById($this->invoiceId)->getBalanceDueMinor());
    }

    public function testDuplicateStoredTokenIsRejectedWithoutChangingFirstPayment(): void
    {
        $data = ['user_id' => 7, 'amount_minor' => 10000, 'currency_code' => 'TRY',
            'status' => Payment::STATUS_PENDING, 'metadata' => ['checkout_token' => 'duplicate']];
        $first = $this->payments->recordPayment($data);
        try {
            $this->payments->recordPayment($data);
            self::fail('Duplicate checkout token must be rejected.');
        } catch (\Coleza\Foundation\Exceptions\ValidationException) {
            self::assertSame($first->getId(), $this->payments->findPaymentByToken('duplicate')->getId());
            self::assertCount(1, $this->payments->listPaymentsForUser(7));
        }
    }

    public function testAnonymousPaymentReadIsRejected(): void
    {
        $this->expectException(AuthorizationException::class);
        (new PaymentApplicationService($this->payments, $this->invoices))->listUserPayments(7);
    }

    public function testGatewayMethodCannotBeSubmittedAsManualPayment(): void
    {
        $this->expectException(\Coleza\Foundation\Exceptions\ValidationException::class);
        (new PaymentApplicationService($this->payments, $this->invoices))->recordManualPayment(
            new RecordManualPaymentCommand(7, $this->invoiceId, 10000, paymentMethod: 'iyzico'), 7);
    }

    public function testProviderExceptionLeavesFailedPaymentWithoutCredit(): void
    {
        $gateway = $this->createMock(\Coleza\Domain\Commerce\Payments\Gateways\PaymentGatewayInterface::class);
        $gateway->expects(self::once())->method('getIdentifier')->willReturn('iyzico');
        $gateway->expects(self::once())->method('initializeCheckout')->willThrowException(new \RuntimeException('Provider unavailable'));
        try {
            (new PaymentApplicationService($this->payments, $this->invoices))->initiateCheckout($gateway,
                new InitiateCheckoutCommand($this->invoiceId, 7, 'Test', 'User', 'test@example.test', '127.0.0.1', 'https://example.test/callback'), 7);
            self::fail('Provider exception must propagate.');
        } catch (\RuntimeException $error) {
            self::assertSame('Provider unavailable', $error->getMessage());
        }
        $payments = $this->payments->listPaymentsForUser(7);
        self::assertCount(1, $payments);
        self::assertSame(Payment::STATUS_FAILED, $payments[0]->getStatus());
        self::assertSame(10000, $this->invoices->findInvoiceById($this->invoiceId)->getBalanceDueMinor());
    }
}
