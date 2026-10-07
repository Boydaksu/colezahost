<?php

declare(strict_types=1);

namespace Coleza\Application\Commerce\Payments;

use Coleza\Domain\Commerce\Invoices\InvoiceService;
use Coleza\Domain\Commerce\Payments\Gateways\PaymentCheckoutRequest;
use Coleza\Domain\Commerce\Payments\Gateways\PaymentCheckoutResponse;
use Coleza\Domain\Commerce\Payments\Gateways\PaymentGatewayInterface;
use Coleza\Domain\Commerce\Payments\Payment;
use Coleza\Domain\Commerce\Payments\PaymentService;
use Coleza\Domain\Commerce\Payments\Refund;
use Coleza\Foundation\Exceptions\AuthorizationException;
use Coleza\Foundation\Exceptions\ResourceNotFoundException;
use Coleza\Foundation\Exceptions\ValidationException;

final class PaymentApplicationService
{
    public function __construct(
        private PaymentService $paymentService,
        private InvoiceService $invoiceService
    ) {
    }

    public function initiateCheckout(
        PaymentGatewayInterface $gateway,
        InitiateCheckoutCommand $command,
        ?int $authUserId = null,
        bool $isAdmin = false
    ): PaymentCheckoutResponse {
        $invoice = $this->invoiceService->findInvoiceById($command->invoiceId);
        if ($invoice === null) {
            throw new ResourceNotFoundException("Invoice {$command->invoiceId} not found.");
        }

        // Access check
        if (!$isAdmin && $authUserId !== null && $invoice->getUserId() !== $authUserId) {
            throw new AuthorizationException('Cannot initiate checkout for an invoice that is not yours.');
        }

        if ($invoice->isPaid()) {
            throw new ValidationException(['invoice' => 'Invoice is already paid.'], 'Already paid');
        }

        $balanceDue = $invoice->getBalanceDueMinor();
        if ($balanceDue <= 0) {
            throw new ValidationException(['amount' => 'Invoice has no remaining balance due.'], 'Zero balance');
        }

        // Generate sequential payment record in PENDING state
        $paymentNumber = $this->paymentService->nextPaymentNumber();

        $checkoutReq = new PaymentCheckoutRequest(
            paymentNumber: $paymentNumber,
            invoiceId: $invoice->getId(),
            amountMinor: $balanceDue,
            currencyCode: $invoice->getCurrencyCode(),
            callbackUrl: $command->callbackUrl,
            buyerId: $command->buyerId,
            buyerName: $command->buyerName,
            buyerSurname: $command->buyerSurname,
            buyerEmail: $command->buyerEmail,
            buyerIp: $command->buyerIp,
            buyerGsm: $command->buyerGsm,
            buyerCity: $command->buyerCity,
            buyerCountry: $command->buyerCountry,
            buyerAddress: $command->buyerAddress
        );

        $response = $gateway->initializeCheckout($checkoutReq);

        if ($response->isSuccess()) {
            // Record pending payment in domain
            $this->paymentService->recordPayment([
                'user_id' => $command->buyerId,
                'invoice_id' => $invoice->getId(),
                'payment_method' => $gateway->getIdentifier(),
                'amount_minor' => $balanceDue,
                'currency_code' => $invoice->getCurrencyCode(),
                'status' => Payment::STATUS_PENDING,
                'metadata' => [
                    'checkout_token' => $response->getToken(),
                    'payment_number' => $paymentNumber,
                ],
            ]);
        }

        return $response;
    }

    public function recordManualPayment(
        RecordManualPaymentCommand $command,
        ?int $authUserId = null,
        bool $isAdmin = false
    ): Payment {
        $invoice = $this->invoiceService->findInvoiceById($command->invoiceId);
        if ($invoice === null) {
            throw new ResourceNotFoundException("Invoice {$command->invoiceId} not found.");
        }

        if (!$isAdmin && $authUserId !== null && $invoice->getUserId() !== $authUserId) {
            throw new AuthorizationException('Cannot record payment for an invoice that does not belong to you.');
        }

        return $this->paymentService->recordPayment([
            'user_id' => $command->userId,
            'invoice_id' => $command->invoiceId,
            'amount_minor' => $command->amountMinor,
            'currency_code' => $command->currencyCode,
            'payment_method' => $command->paymentMethod,
            'transaction_reference' => $command->transactionReference,
            'proof_document_url' => $command->proofDocumentUrl,
            'notes' => $command->notes,
            'metadata' => $command->metadata,
        ]);
    }

    public function getPayment(int $paymentId, ?int $authUserId = null, bool $isAdmin = false): Payment
    {
        $payment = $this->paymentService->findPaymentById($paymentId);
        if ($payment === null) {
            throw new ResourceNotFoundException("Payment {$paymentId} not found.");
        }

        if (!$isAdmin && $authUserId !== null && $payment->getUserId() !== $authUserId) {
            throw new AuthorizationException('Forbidden: You do not have access to this payment.');
        }

        return $payment;
    }

    /**
     * @return array<Payment>
     */
    public function listUserPayments(int $userId, ?int $authUserId = null, bool $isAdmin = false): array
    {
        if (!$isAdmin && $authUserId !== null && $userId !== $authUserId) {
            throw new AuthorizationException('Forbidden: Cannot list payments for another user.');
        }

        return $this->paymentService->listPaymentsForUser($userId);
    }

    public function processRefund(
        int $paymentId,
        int $amountMinor,
        string $reason,
        ?PaymentGatewayInterface $gateway = null,
        bool $isAdmin = false
    ): Refund {
        // Refund is strictly an admin/financial privileged action
        if (!$isAdmin) {
            throw new AuthorizationException('Only administrative staff can process refunds.');
        }

        if ($gateway !== null) {
            return $this->paymentService->refundViaGateway(
                paymentId: $paymentId,
                amountMinor: $amountMinor,
                reason: $reason,
                gateway: $gateway
            );
        }

        return $this->paymentService->recordRefund([
            'payment_id' => $paymentId,
            'amount_minor' => $amountMinor,
            'reason' => $reason,
            'refund_method' => Refund::METHOD_MANUAL,
        ]);
    }
}
