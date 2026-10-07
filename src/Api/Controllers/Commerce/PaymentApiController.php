<?php

declare(strict_types=1);

namespace Coleza\Api\Controllers\Commerce;

use Coleza\Api\Response\ApiResponse;
use Coleza\Application\Commerce\Payments\InitiateCheckoutCommand;
use Coleza\Application\Commerce\Payments\PaymentApplicationService;
use Coleza\Application\Commerce\Payments\RecordManualPaymentCommand;
use Coleza\Domain\Commerce\Payments\Gateways\PaymentGatewayInterface;
use Coleza\Foundation\Exceptions\ValidationException;

final class PaymentApiController
{
    public function __construct(
        private PaymentApplicationService $paymentAppService
    ) {
    }

    /**
     * @param array<string, mixed> $requestData
     * @param array<string, mixed> $authContext
     * @return array<string, mixed>
     */
    public function checkout(array $requestData, PaymentGatewayInterface $gateway, array $authContext): array
    {
        $userId = (int) ($authContext['user_id'] ?? 0);
        $isAdmin = (bool) ($authContext['is_admin'] ?? false);

        $invoiceId = (int) ($requestData['invoice_id'] ?? 0);
        if ($invoiceId <= 0) {
            throw new ValidationException(['invoice_id' => 'Valid invoice_id required.'], 'Invalid invoice');
        }

        $command = new InitiateCheckoutCommand(
            invoiceId: $invoiceId,
            buyerId: $userId,
            buyerName: (string) ($requestData['buyer_name'] ?? 'Coleza User'),
            buyerSurname: (string) ($requestData['buyer_surname'] ?? 'Customer'),
            buyerEmail: (string) ($requestData['buyer_email'] ?? 'customer@example.com'),
            buyerIp: (string) ($requestData['buyer_ip'] ?? '127.0.0.1'),
            callbackUrl: (string) ($requestData['callback_url'] ?? 'https://panel.coleza.com/callback'),
            buyerGsm: isset($requestData['buyer_gsm']) ? (string) $requestData['buyer_gsm'] : null,
            buyerCity: (string) ($requestData['buyer_city'] ?? 'Istanbul'),
            buyerCountry: (string) ($requestData['buyer_country'] ?? 'Turkey'),
            buyerAddress: (string) ($requestData['buyer_address'] ?? 'Default Address')
        );

        $response = $this->paymentAppService->initiateCheckout($gateway, $command, $userId, $isAdmin);

        return ApiResponse::success([
            'success' => $response->isSuccess(),
            'token' => $response->getToken(),
            'checkout_url' => $response->getCheckoutUrl(),
            'checkout_form_content' => $response->getCheckoutFormContent(),
        ], ['action' => 'checkout_initiated']);
    }

    /**
     * @param array<string, mixed> $requestData
     * @param array<string, mixed> $authContext
     * @return array<string, mixed>
     */
    public function submitManual(array $requestData, array $authContext): array
    {
        $userId = (int) ($authContext['user_id'] ?? 0);
        $isAdmin = (bool) ($authContext['is_admin'] ?? false);

        $command = new RecordManualPaymentCommand(
            userId: $userId,
            invoiceId: (int) ($requestData['invoice_id'] ?? 0),
            amountMinor: (int) ($requestData['amount_minor'] ?? 0),
            currencyCode: (string) ($requestData['currency_code'] ?? 'TRY'),
            paymentMethod: (string) ($requestData['payment_method'] ?? 'bank_transfer'),
            transactionReference: isset($requestData['transaction_reference']) ? (string) $requestData['transaction_reference'] : null,
            proofDocumentUrl: isset($requestData['proof_document_url']) ? (string) $requestData['proof_document_url'] : null,
            notes: isset($requestData['notes']) ? (string) $requestData['notes'] : null,
            metadata: (array) ($requestData['metadata'] ?? [])
        );

        $payment = $this->paymentAppService->recordManualPayment($command, $userId, $isAdmin);

        return ApiResponse::success([
            'payment_id' => $payment->getId(),
            'payment_number' => $payment->getPaymentNumber(),
            'status' => $payment->getStatus(),
            'amount_minor' => $payment->getAmountMinor(),
        ], ['action' => 'manual_payment_submitted'], 201);
    }

    /**
     * @param array<string, mixed> $authContext
     * @return array<string, mixed>
     */
    public function show(int $paymentId, array $authContext): array
    {
        $userId = (int) ($authContext['user_id'] ?? 0);
        $isAdmin = (bool) ($authContext['is_admin'] ?? false);

        $payment = $this->paymentAppService->getPayment($paymentId, $userId, $isAdmin);

        return ApiResponse::success([
            'id' => $payment->getId(),
            'payment_number' => $payment->getPaymentNumber(),
            'status' => $payment->getStatus(),
            'amount_minor' => $payment->getAmountMinor(),
            'currency_code' => $payment->getCurrencyCode(),
            'payment_method' => $payment->getPaymentMethod(),
            'paid_at' => $payment->getPaidAt(),
        ]);
    }

    /**
     * @param array<string, mixed> $authContext
     * @return array<string, mixed>
     */
    public function list(array $authContext): array
    {
        $userId = (int) ($authContext['user_id'] ?? 0);
        $isAdmin = (bool) ($authContext['is_admin'] ?? false);

        $payments = $this->paymentAppService->listUserPayments($userId, $userId, $isAdmin);

        $data = array_map(fn($p) => [
            'id' => $p->getId(),
            'payment_number' => $p->getPaymentNumber(),
            'status' => $p->getStatus(),
            'amount_minor' => $p->getAmountMinor(),
            'currency_code' => $p->getCurrencyCode(),
            'payment_method' => $p->getPaymentMethod(),
        ], $payments);

        return ApiResponse::success($data, ['total' => count($data)]);
    }

    /**
     * @param array<string, mixed> $requestData
     * @param array<string, mixed> $authContext
     * @return array<string, mixed>
     */
    public function refund(int $paymentId, array $requestData, ?PaymentGatewayInterface $gateway, array $authContext): array
    {
        $isAdmin = (bool) ($authContext['is_admin'] ?? false);
        $amountMinor = (int) ($requestData['amount_minor'] ?? 0);
        $reason = (string) ($requestData['reason'] ?? 'Admin refund');

        $refund = $this->paymentAppService->processRefund(
            paymentId: $paymentId,
            amountMinor: $amountMinor,
            reason: $reason,
            gateway: $gateway,
            isAdmin: $isAdmin
        );

        return ApiResponse::success([
            'refund_id' => $refund->getId(),
            'refund_number' => $refund->getRefundNumber(),
            'amount_minor' => $refund->getAmountMinor(),
            'reason' => $refund->getReason(),
            'refunded_at' => $refund->getRefundedAt(),
        ], ['action' => 'payment_refunded']);
    }
}
