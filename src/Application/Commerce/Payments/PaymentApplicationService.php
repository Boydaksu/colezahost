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
        $this->requireAuthenticatedUser($authUserId);
        $invoice = $this->invoiceService->findInvoiceById($command->invoiceId);
        if ($invoice === null) {
            throw new ResourceNotFoundException("Invoice {$command->invoiceId} not found.");
        }

        // Access check
        if (!$isAdmin && $authUserId !== null && $invoice->getUserId() !== $authUserId) {
            throw new AuthorizationException('Cannot initiate checkout for an invoice that is not yours.');
        }

        if ($command->buyerId !== $invoice->getUserId()) {
            throw new AuthorizationException('Checkout buyer must match invoice owner.');
        }

        if ($invoice->isPaid()) {
            throw new ValidationException(['invoice' => 'Invoice is already paid.'], 'Already paid');
        }

        $balanceDue = $invoice->getBalanceDueMinor();
        if ($balanceDue <= 0) {
            throw new ValidationException(['amount' => 'Invoice has no remaining balance due.'], 'Zero balance');
        }

        // Generate sequential payment record in PENDING state
        $payment = $this->paymentService->recordPayment([
            'user_id' => $invoice->getUserId(),
            'invoice_id' => $invoice->getId(),
            'payment_method' => $gateway->getIdentifier(),
            'amount_minor' => $balanceDue,
            'currency_code' => $invoice->getCurrencyCode(),
            'status' => Payment::STATUS_PENDING,
        ]);
        $paymentNumber = $payment->getPaymentNumber();

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

        try {
            $response = $gateway->initializeCheckout($checkoutReq);
        } catch (\Throwable $error) {
            $this->paymentService->failPaymentFromGateway((int) $payment->getId(), 'Checkout provider request failed.');
            throw $error;
        }

        if ($response->isSuccess()) {
            if (trim((string) $response->getToken()) === '') {
                $this->paymentService->failPaymentFromGateway((int) $payment->getId(), 'Checkout provider returned no token.');
                throw new ValidationException(['checkout_token' => 'Provider returned no checkout token.'], 'Invalid checkout response');
            }
            $this->paymentService->attachCheckoutToken((int) $payment->getId(), (string) $response->getToken());
        } else {
            $this->paymentService->failPaymentFromGateway((int) $payment->getId(), 'Checkout initialization failed.');
        }

        return $response;
    }

    public function recordManualPayment(
        RecordManualPaymentCommand $command,
        ?int $authUserId = null,
        bool $isAdmin = false
    ): Payment {
        $this->requireAuthenticatedUser($authUserId);
        $invoice = $this->invoiceService->findInvoiceById($command->invoiceId);
        if ($invoice === null) {
            throw new ResourceNotFoundException("Invoice {$command->invoiceId} not found.");
        }

        if (!$isAdmin && $authUserId !== null && $invoice->getUserId() !== $authUserId) {
            throw new AuthorizationException('Cannot record payment for an invoice that does not belong to you.');
        }

        if ($command->userId !== $invoice->getUserId()) {
            throw new AuthorizationException('Payment user must match invoice owner.');
        }
        if (!in_array($command->paymentMethod, [Payment::METHOD_BANK_TRANSFER, 'manual'], true)) {
            throw new ValidationException(['payment_method' => 'Only manual payment submissions are accepted.'], 'Invalid payment method');
        }
        if ($command->amountMinor > $invoice->getBalanceDueMinor()) {
            throw new ValidationException(['amount_minor' => 'Submission exceeds invoice balance.'], 'Invalid payment amount');
        }

        return $this->paymentService->recordPayment([
            'user_id' => $command->userId,
            'invoice_id' => $command->invoiceId,
            'amount_minor' => $command->amountMinor,
            'currency_code' => $command->currencyCode,
            'payment_method' => $command->paymentMethod,
            'status' => Payment::STATUS_PENDING,
            'transaction_reference' => $command->transactionReference,
            'proof_document_url' => $command->proofDocumentUrl,
            'notes' => $command->notes,
            'metadata' => $command->metadata,
        ]);
    }

    public function getPayment(int $paymentId, ?int $authUserId = null, bool $isAdmin = false): Payment
    {
        $this->requireAuthenticatedUser($authUserId);
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
        $this->requireAuthenticatedUser($authUserId);
        if (!$isAdmin && $authUserId !== null && $userId !== $authUserId) {
            throw new AuthorizationException('Forbidden: Cannot list payments for another user.');
        }

        return $this->paymentService->listPaymentsForUser($userId);
    }

    private function requireAuthenticatedUser(?int $authUserId): void
    {
        if ($authUserId === null || $authUserId <= 0) {
            throw new AuthorizationException('Authenticated user is required.');
        }
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
