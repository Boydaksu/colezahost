<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Payments;

use Coleza\Domain\Commerce\Payments\Gateways\Iyzico\IyzicoConfiguration;
use Coleza\Domain\Commerce\Payments\Gateways\Iyzico\IyzicoPaymentGateway;
use Coleza\Domain\Commerce\Payments\Gateways\PaymentCheckoutRequest;
use Coleza\Domain\Commerce\Payments\Gateways\PaymentRefundRequest;
use Coleza\Domain\Commerce\Payments\Gateways\PaymentVerificationRequest;
use PHPUnit\Framework\TestCase;

final class IyzicoPaymentGatewayTest extends TestCase
{
    private IyzicoConfiguration $config;

    protected function setUp(): void
    {
        $this->config = new IyzicoConfiguration(
            apiKey: 'sandbox-apiKey-123',
            secretKey: 'sandbox-secretKey-456',
            baseUrl: 'https://sandbox-api.iyzipay.com',
            isTestMode: true
        );
    }

    public function testIyzicoConfigurationMasking(): void
    {
        $this->assertSame('sandbox-apiKey-123', $this->config->getApiKey());
        $this->assertSame('sandbox-secretKey-456', $this->config->getSecretKey());
        $this->assertSame('san******456', $this->config->getMaskedSecretKey());

        $safe = $this->config->toSafeArray();
        $this->assertSame('san******456', $safe['secretKey']);
        $this->assertStringNotContainsString('secretKey-456', json_encode($safe));
    }

    public function testAuthorizationHeaderGeneration(): void
    {
        $gateway = new IyzicoPaymentGateway($this->config);
        $header = $gateway->generateAuthorizationHeader('{"test": true}', 'random123');

        $this->assertStringStartsWith('IYZWS sandbox-apiKey-123:', $header);
    }

    public function testInitializeCheckoutSuccess(): void
    {
        $mockHttpClient = function (string $url, string $payload, array $headers): array {
            return [
                'code' => 200,
                'body' => json_encode([
                    'status' => 'success',
                    'token' => 'iyz_token_xyz_998877',
                    'checkoutFormContent' => '<script>iyzicoCheckout()</script>',
                    'paymentPageUrl' => 'https://sandbox-cpp.iyzipay.com?token=iyz_token_xyz_998877',
                ]),
            ];
        };

        $gateway = new IyzicoPaymentGateway($this->config, $mockHttpClient);

        $request = new PaymentCheckoutRequest(
            paymentNumber: 'PAY-20261007-000001',
            invoiceId: 10,
            amountMinor: 25000,
            currencyCode: 'TRY',
            callbackUrl: 'https://panel.coleza.com/callback/iyzico',
            buyerId: 5,
            buyerName: 'Ahmet',
            buyerSurname: 'Kaya',
            buyerEmail: 'ahmet@example.com',
            buyerIp: '88.240.10.20'
        );

        $response = $gateway->initializeCheckout($request);

        $this->assertTrue($response->isSuccess());
        $this->assertSame('iyz_token_xyz_998877', $response->getToken());
        $this->assertStringContainsString('iyzicoCheckout()', (string) $response->getCheckoutFormContent());
        $this->assertStringContainsString('https://sandbox-cpp.iyzipay.com', (string) $response->getCheckoutUrl());
    }

    public function testInitializeCheckoutFailure(): void
    {
        $mockHttpClient = function (string $url, string $payload, array $headers): array {
            return [
                'code' => 200,
                'body' => json_encode([
                    'status' => 'failure',
                    'errorMessage' => 'Buyer email format is invalid.',
                ]),
            ];
        };

        $gateway = new IyzicoPaymentGateway($this->config, $mockHttpClient);

        $request = new PaymentCheckoutRequest(
            paymentNumber: 'PAY-20261007-000002',
            invoiceId: 11,
            amountMinor: 10000,
            currencyCode: 'TRY',
            callbackUrl: 'https://panel.coleza.com/callback/iyzico',
            buyerId: 6,
            buyerName: 'Mehmet',
            buyerSurname: 'Can',
            buyerEmail: 'invalid-email',
            buyerIp: '127.0.0.1'
        );

        $response = $gateway->initializeCheckout($request);

        $this->assertFalse($response->isSuccess());
        $this->assertSame('Buyer email format is invalid.', $response->getErrorMessage());
        $this->assertNull($response->getToken());
    }

    public function testVerifyPaymentSuccess(): void
    {
        $mockHttpClient = function (string $url, string $payload, array $headers): array {
            return [
                'code' => 200,
                'body' => json_encode([
                    'status' => 'success',
                    'paymentStatus' => 'SUCCESS',
                    'paymentId' => '99887766',
                    'paidPrice' => '150.00',
                    'currency' => 'TRY',
                    'iyziCommissionRateAmount' => '4.20',
                    'iyziCommissionFee' => '0.30',
                    'cardAssociation' => 'MASTER_CARD',
                    'cardFamily' => 'Bonus',
                    'installment' => 1,
                    'itemTransactions' => [
                        [
                            'paymentTransactionId' => 'txn_item_112233',
                            'itemId' => 'inv_10',
                            'paidPrice' => '150.00',
                        ],
                    ],
                ]),
            ];
        };

        $gateway = new IyzicoPaymentGateway($this->config, $mockHttpClient);

        $verificationRequest = new PaymentVerificationRequest(
            token: 'valid_iyzico_token_123'
        );

        $response = $gateway->verifyPayment($verificationRequest);

        $this->assertTrue($response->isSuccess());
        $this->assertSame('99887766', $response->getPaymentId());
        $this->assertSame('txn_item_112233', $response->getPaymentTransactionId());
        $this->assertSame(15000, $response->getPaidAmountMinor());
        $this->assertSame(450, $response->getFeeMinor()); // 4.20 + 0.30 = 4.50 TRY -> 450 minor
        $this->assertSame('TRY', $response->getCurrency());
        $this->assertSame('MASTER_CARD', $response->getCardAssociation());
        $this->assertSame('Bonus', $response->getCardFamily());
        $this->assertSame(1, $response->getInstallments());
    }

    public function testRefundSuccess(): void
    {
        $mockHttpClient = function (string $url, string $payload, array $headers): array {
            return [
                'code' => 200,
                'body' => json_encode([
                    'status' => 'success',
                    'paymentId' => '99887766',
                    'price' => '50.00',
                ]),
            ];
        };

        $gateway = new IyzicoPaymentGateway($this->config, $mockHttpClient);

        $refundRequest = new PaymentRefundRequest(
            paymentTransactionId: 'txn_item_112233',
            refundAmountMinor: 5000,
            currencyCode: 'TRY',
            reason: 'Customer requested plan downgrade'
        );

        $response = $gateway->refund($refundRequest);

        $this->assertTrue($response->isSuccess());
        $this->assertSame('99887766', $response->getRefundId());
        $this->assertSame(5000, $response->getRefundedAmountMinor());
    }
}
