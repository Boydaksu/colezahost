<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Payments\Gateways\Iyzico;

use Coleza\Domain\Commerce\Payments\Gateways\PaymentCheckoutRequest;
use Coleza\Domain\Commerce\Payments\Gateways\PaymentCheckoutResponse;
use Coleza\Domain\Commerce\Payments\Gateways\PaymentGatewayInterface;
use Coleza\Domain\Commerce\Payments\Gateways\PaymentRefundRequest;
use Coleza\Domain\Commerce\Payments\Gateways\PaymentRefundResponse;
use Coleza\Domain\Commerce\Payments\Gateways\PaymentVerificationRequest;
use Coleza\Domain\Commerce\Payments\Gateways\PaymentVerificationResponse;
use Throwable;

final class IyzicoPaymentGateway implements PaymentGatewayInterface
{
    /**
     * @var callable|null
     */
    private $httpClient;

    public function __construct(
        private IyzicoConfiguration $config,
        ?callable $httpClient = null
    ) {
        $this->httpClient = $httpClient;
    }

    public function getGatewayId(): string
    {
        return 'iyzico';
    }

    public function getIdentifier(): string
    {
        return 'iyzico';
    }

    public function getDisplayName(): string
    {
        return 'iyzico (Kredi / Banka Kartı)';
    }

    public function initializeCheckout(PaymentCheckoutRequest $request): PaymentCheckoutResponse
    {
        $priceFormatted = number_format($request->getAmountDecimal(), 2, '.', '');
        $buyerFullName = trim($request->getBuyerName() . ' ' . $request->getBuyerSurname());

        $basketItems = [];
        if (!empty($request->getItems())) {
            foreach ($request->getItems() as $item) {
                $basketItems[] = [
                    'id' => (string) ($item['id'] ?? 'item_1'),
                    'name' => (string) ($item['name'] ?? 'Hosting Service'),
                    'category1' => (string) ($item['category'] ?? 'Hosting'),
                    'itemType' => 'VIRTUAL',
                    'price' => number_format(((int) ($item['priceMinor'] ?? $request->getAmountMinor())) / 100.0, 2, '.', ''),
                ];
            }
        } else {
            $basketItems[] = [
                'id' => 'inv_' . $request->getInvoiceId(),
                'name' => 'Fatura #' . $request->getInvoiceId(),
                'category1' => 'Hosting',
                'itemType' => 'VIRTUAL',
                'price' => $priceFormatted,
            ];
        }

        $payload = [
            'locale' => 'tr',
            'conversationId' => $request->getPaymentNumber(),
            'price' => $priceFormatted,
            'paidPrice' => $priceFormatted,
            'currency' => $request->getCurrencyCode(),
            'basketId' => 'INV-' . $request->getInvoiceId(),
            'paymentGroup' => 'PRODUCT',
            'callbackUrl' => $request->getCallbackUrl(),
            'enabledInstallments' => [1, 2, 3, 6, 9, 12],
            'buyer' => [
                'id' => (string) $request->getBuyerId(),
                'name' => $request->getBuyerName(),
                'surname' => $request->getBuyerSurname(),
                'gsmNumber' => $request->getBuyerGsm() ?? '+905550000000',
                'email' => $request->getBuyerEmail(),
                'identityNumber' => '11111111111',
                'registrationAddress' => $request->getBuyerAddress(),
                'ip' => $request->getBuyerIp(),
                'city' => $request->getBuyerCity(),
                'country' => $request->getBuyerCountry(),
            ],
            'shippingAddress' => [
                'contactName' => $buyerFullName,
                'city' => $request->getBuyerCity(),
                'country' => $request->getBuyerCountry(),
                'address' => $request->getBuyerAddress(),
            ],
            'billingAddress' => [
                'contactName' => $buyerFullName,
                'city' => $request->getBuyerCity(),
                'country' => $request->getBuyerCountry(),
                'address' => $request->getBuyerAddress(),
            ],
            'basketItems' => $basketItems,
        ];

        try {
            $response = $this->callApi('/payment/iyzipay/checkoutform/initialize/authecom', $payload);

            if (($response['status'] ?? '') === 'success' && !empty($response['token'])) {
                $token = (string) $response['token'];
                $formContent = $response['checkoutFormContent'] ?? null;
                $paymentPageUrl = $response['paymentPageUrl'] ?? null;

                return PaymentCheckoutResponse::successful(
                    token: $token,
                    checkoutFormContent: $formContent,
                    checkoutUrl: $paymentPageUrl,
                    rawPayload: $response
                );
            }

            $errorMessage = $response['errorMessage'] ?? 'iyzico checkout initialization failed.';
            return PaymentCheckoutResponse::failure($errorMessage, $response);
        } catch (Throwable $e) {
            return PaymentCheckoutResponse::failure($e->getMessage());
        }
    }

    public function verifyPayment(PaymentVerificationRequest $request): PaymentVerificationResponse
    {
        $payload = [
            'locale' => 'tr',
            'token' => $request->getToken(),
        ];

        try {
            $response = $this->callApi('/payment/iyzipay/checkoutform/auth/ecom/detail', $payload);

            $status = $response['status'] ?? '';
            $paymentStatus = $response['paymentStatus'] ?? '';

            if ($status === 'success' && $paymentStatus === 'SUCCESS') {
                $paidPrice = (float) ($response['paidPrice'] ?? 0.0);
                $paidMinor = (int) round($paidPrice * 100);

                // iyzico fee calculation if returned
                $iyziFee = (float) ($response['iyziCommissionRateAmount'] ?? 0.0) + (float) ($response['iyziCommissionFee'] ?? 0.0);
                $feeMinor = (int) round($iyziFee * 100);

                $paymentId = (string) ($response['paymentId'] ?? '');
                $itemTransactions = $response['itemTransactions'] ?? [];
                $paymentTransactionId = !empty($itemTransactions) 
                    ? (string) ($itemTransactions[0]['paymentTransactionId'] ?? $paymentId) 
                    : $paymentId;

                $paymentNumber = isset($response['conversationId']) ? (string) $response['conversationId'] : null;

                return PaymentVerificationResponse::successful(
                    paymentId: $paymentId,
                    paymentTransactionId: $paymentTransactionId,
                    paidAmountMinor: $paidMinor,
                    currency: (string) ($response['currency'] ?? 'TRY'),
                    feeMinor: $feeMinor,
                    cardAssociation: $response['cardAssociation'] ?? null,
                    cardFamily: $response['cardFamily'] ?? null,
                    installments: (int) ($response['installment'] ?? 1),
                    paymentNumber: $paymentNumber,
                    rawPayload: $response
                );
            }

            $errorMessage = $response['errorMessage'] ?? 'Payment verification failed.';
            return PaymentVerificationResponse::failure($errorMessage, $response);
        } catch (Throwable $e) {
            return PaymentVerificationResponse::failure($e->getMessage());
        }
    }

    public function refund(PaymentRefundRequest $request): PaymentRefundResponse
    {
        $priceFormatted = number_format($request->getRefundAmountDecimal(), 2, '.', '');

        $payload = [
            'locale' => 'tr',
            'conversationId' => 'REF-' . bin2hex(random_bytes(6)),
            'paymentTransactionId' => $request->getPaymentTransactionId(),
            'price' => $priceFormatted,
            'currency' => $request->getCurrencyCode(),
            'ip' => '127.0.0.1',
        ];

        try {
            $response = $this->callApi('/payment/refund', $payload);

            if (($response['status'] ?? '') === 'success') {
                $refundId = (string) ($response['paymentId'] ?? $response['conversationId'] ?? bin2hex(random_bytes(6)));
                return PaymentRefundResponse::successful(
                    refundId: $refundId,
                    refundedAmountMinor: $request->getRefundAmountMinor(),
                    rawPayload: $response
                );
            }

            $errorMessage = $response['errorMessage'] ?? 'iyzico refund execution failed.';
            $errorCode = isset($response['errorCode']) ? (string) $response['errorCode'] : null;
            return PaymentRefundResponse::failure($errorMessage, $errorCode, $response);
        } catch (Throwable $e) {
            return PaymentRefundResponse::failure($e->getMessage(), 'EXCEPTION');
        }
    }

    /**
     * @param string $path
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function callApi(string $path, array $payload): array
    {
        $url = $this->config->getBaseUrl() . $path;
        $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR);

        $randomString = bin2hex(random_bytes(8));
        $authHeader = $this->generateAuthorizationHeader($jsonPayload, $randomString);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: ' . $authHeader,
            'x-iyzi-rnd: ' . $randomString,
            'x-iyzi-client-version: coleza-iyzico-1.0',
        ];

        if ($this->httpClient !== null) {
            $res = ($this->httpClient)($url, $jsonPayload, $headers);
            $body = (string) ($res['body'] ?? '{}');
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR) ?? [];
        }

        // Native shared-host compatible stream HTTP client
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $jsonPayload,
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        $fp = fopen($url, 'r', false, $ctx);
        if ($fp === false) {
            throw new \RuntimeException("Failed to connect to iyzico API endpoint: {$url}");
        }

        $responseContent = stream_get_contents($fp) ?: '{}';
        fclose($fp);

        return json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR) ?? [];
    }

    public function generateAuthorizationHeader(string $payload, string $randomString): string
    {
        $apiKey = $this->config->getApiKey();
        $secretKey = $this->config->getSecretKey();

        $hashString = $apiKey . $randomString . $secretKey . $payload;
        $hash = base64_encode(sha1($hashString, true));

        return "IYZWS {$apiKey}:{$hash}";
    }
}
