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
    private \Coleza\Application\Identity\CommandAuthorizer $authorization;

    public function __construct(
        private PaymentService $paymentService,
        private InvoiceService $invoiceService,
        private ?\Coleza\Application\Identity\AuthenticationService $authentication = null
    ) {
        $this->authorization = new \Coleza\Application\Identity\CommandAuthorizer($this->paymentService->connection());
        if ($this->invoiceService->connection() !== $this->paymentService->connection()) { throw new \InvalidArgumentException("Payment dependencies must share a connection."); }
    }

    public function initiateCheckout(
        PaymentGatewayInterface $gateway,
        InitiateCheckoutCommand $command,
        ?int $authUserId = null,
        bool $isAdmin = false
    ): PaymentCheckoutResponse {
        [$payment, $invoice, $balanceDue] = $this->authorization->run($authUserId, function () use ($command, $authUserId, $gateway): array {

        $this->requireAuthenticatedUser($authUserId);
        $invoice = $this->invoiceService->findInvoiceById($command->invoiceId);
        if ($invoice === null) {
            throw new ResourceNotFoundException("Invoice {$command->invoiceId} not found.");
        }

        // Access check

        $this->authorization->resource($authUserId, $invoice->getUserId(), $invoice->getOrganizationId(), 'payments.manage', 'org.billing.manage');
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
            return [$payment, $invoice, $balanceDue];
        }, 'command.checkout_reserved');
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
        return $this->authorization->run($authUserId, function () use ($command, $authUserId, $isAdmin): Payment {
            $isAdmin = $this->authorization->hasSystemPermission($authUserId, 'payments.manage');

            $this->requireAuthenticatedUser($authUserId);
            $invoice = $this->invoiceService->findInvoiceById($command->invoiceId);
            if ($invoice === null) {
                throw new ResourceNotFoundException("Invoice {$command->invoiceId} not found.");
            }


            $this->authorization->resource($authUserId, $invoice->getUserId(), $invoice->getOrganizationId(), 'payments.manage', 'org.billing.manage');
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

        }, 'command.manual_payment_submitted');
    }

    public function getPayment(int $paymentId, ?int $authUserId = null, bool $isAdmin = false): Payment
    {
        return $this->authorization->run($authUserId, function () use ($paymentId, $authUserId, $isAdmin): Payment {
            $isAdmin = $this->authorization->hasSystemPermission($authUserId, 'payments.view');

            $this->requireAuthenticatedUser($authUserId);
            $payment = $this->paymentService->findPaymentById($paymentId);
            if ($payment === null) {
                throw new ResourceNotFoundException("Payment {$paymentId} not found.");
            }


            $organization = $payment->getInvoiceId() === null ? null : $this->invoiceService->findInvoiceById($payment->getInvoiceId())?->getOrganizationId();
            $this->authorization->resource($authUserId, $payment->getUserId(), $organization, 'payments.view', 'org.billing.view');
            return $payment;

        }, null);
    }

    /**
     * @return array<Payment>
     */
    public function listUserPayments(int $userId, ?int $authUserId = null, bool $isAdmin = false): array
    {
        return $this->authorization->run($authUserId, function () use ($userId, $authUserId, $isAdmin): array {
            $isAdmin = $this->authorization->hasSystemPermission($authUserId, 'payments.view');

            $this->requireAuthenticatedUser($authUserId);
            if (!$isAdmin && $authUserId !== null && $userId !== $authUserId) {
                throw new AuthorizationException('Forbidden: Cannot list payments for another user.');
            }

            $rows = $this->paymentService->listPaymentsForUser($userId);
            return array_values(array_filter($rows, function ($row) use ($authUserId): bool {
                $organization = $row->getInvoiceId() === null ? null : $this->invoiceService->findInvoiceById($row->getInvoiceId())?->getOrganizationId();
                try { $this->authorization->resource($authUserId, $row->getUserId(), $organization, 'payments.view', 'org.billing.view'); return true; }
                catch (AuthorizationException) { return false; }
            }));

        }, null);
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
        bool $isAdmin = false,
        ?int $authUserId = null,
        ?string $sessionId = null,
        ?string $stepUpToken = null
    ): Refund {
        $authorize = function () use ($paymentId, $authUserId, $sessionId, $stepUpToken): void {
            $this->authorization->requireSystem($authUserId, 'payments.refund');
            if ($sessionId === null || $stepUpToken === null) { throw new AuthorizationException('A current administrative staff session and step-up are required.'); }
            $authentication = $this->authentication ?? new \Coleza\Application\Identity\AuthenticationService($this->paymentService->connection(), \Coleza\Domain\Vault\ApplicationKey::encryptor(\Coleza\Domain\Vault\ApplicationKey::configured()));
            if (!$authentication->usesConnection($this->paymentService->connection()) || (int)$authentication->sessionUser($sessionId)['id'] !== $authUserId) { throw new AuthorizationException('Refund actor and session differ.'); }
            $authentication->consumeStepUp($sessionId, 'payments.refund:' . $paymentId, $stepUpToken);
        };
        if ($gateway !== null) {
            $this->authorization->run($authUserId, $authorize, 'command.remote_refund_authorized');
            return $this->paymentService->refundViaGateway($paymentId, $amountMinor, $reason, $gateway);
        }
        return $this->authorization->run($authUserId, function () use ($authorize, $paymentId, $amountMinor, $reason): Refund {
            $authorize();
            return $this->paymentService->recordRefund(['payment_id' => $paymentId, 'amount_minor' => $amountMinor, 'reason' => $reason, 'refund_method' => Refund::METHOD_MANUAL]);
        }, 'command.manual_refund_recorded');
    }
}
