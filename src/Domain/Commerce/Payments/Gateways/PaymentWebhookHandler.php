<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Payments\Gateways;

use Coleza\Domain\Commerce\Invoices\InvoiceService;
use Coleza\Domain\Commerce\Orders\OrderService;
use Coleza\Domain\Commerce\Payments\Gateways\Iyzico\IyzicoPaymentGateway;
use Coleza\Domain\Commerce\Payments\Payment;
use Coleza\Domain\Commerce\Payments\PaymentService;
use Coleza\Foundation\Database\Connection;
use RuntimeException;
use Throwable;

final class PaymentWebhookHandler
{
    private string $webhookEventsTable = 'payment_webhook_events';

    public function __construct(
        private Connection $db,
        private PaymentService $paymentService,
        private ?InvoiceService $invoiceService = null,
        private ?OrderService $orderService = null
    ) {
        $this->paymentService->ensureTables();
    }

    /**
     * Handle incoming iyzico callback POST request.
     *
     * @param array<string, mixed> $postData
     */
    public function handleIyzicoCallback(
        IyzicoPaymentGateway $gateway,
        array $postData,
        ?string $rawPayload = null
    ): PaymentWebhookResult {
        $token = isset($postData['token']) ? trim((string) $postData['token']) : '';
        if ($token === '') {
            return PaymentWebhookResult::failed('Token missing in callback payload.');
        }

        $eventId = 'iyzico_token_' . $token;
        $gatewayName = $gateway->getIdentifier();
        $payloadHash = hash('sha256', $rawPayload ?? (string) json_encode($postData));

        // 1. Idempotency Check: Verify if event has already been processed
        $existingEvent = $this->findEvent($gatewayName, $eventId);
        if ($existingEvent !== null && $existingEvent['status'] === PaymentWebhookEvent::STATUS_PROCESSED) {
            $paymentId = $existingEvent['payment_id'] !== null ? (int) $existingEvent['payment_id'] : null;
            $payment = $paymentId !== null ? $this->paymentService->findPaymentById($paymentId) : null;
            $paymentNumber = $payment ? $payment->getPaymentNumber() : '';

            return PaymentWebhookResult::duplicate(
                paymentId: $paymentId ?? 0,
                paymentNumber: $paymentNumber,
                message: 'Webhook/callback event already processed successfully (idempotent duplicate).',
                eventId: $eventId,
                metadata: ['existing_event_id' => $existingEvent['id']]
            );
        }

        // 2. Gateway Verification Request
        $verificationRequest = new PaymentVerificationRequest($token);
        $verificationResponse = $gateway->verifyPayment($verificationRequest);

        // 3. Resolve associated payment
        $payment = null;
        $paymentNumber = $verificationResponse->getPaymentNumber() ?? ($postData['conversationId'] ?? null);
        if ($paymentNumber !== null && $paymentNumber !== '') {
            $payment = $this->paymentService->findPaymentByNumber((string) $paymentNumber);
        }

        if ($payment === null) {
            $payment = $this->paymentService->findPaymentByToken($token);
        }

        // 4. Handle Verification Failure
        if (!$verificationResponse->isSuccess()) {
            $errorMessage = $verificationResponse->getErrorMessage() ?? 'Gateway payment verification failed.';

            if ($payment !== null && $payment->isPending()) {
                $this->paymentService->failPaymentFromGateway($payment->getId(), $errorMessage);
            }

            $this->recordEvent(
                gateway: $gatewayName,
                eventId: $eventId,
                eventType: 'callback.failure',
                payloadHash: $payloadHash,
                status: PaymentWebhookEvent::STATUS_FAILED,
                paymentId: $payment?->getId(),
                errorMessage: $errorMessage,
                payload: $postData
            );

            return PaymentWebhookResult::failed(
                message: $errorMessage,
                paymentId: $payment?->getId(),
                paymentNumber: $payment?->getPaymentNumber(),
                eventId: $eventId,
                metadata: $verificationResponse->getRawPayload()
            );
        }

        // 5. Associated Payment Validation
        if ($payment === null) {
            $error = 'Associated payment record could not be matched for token.';
            $this->recordEvent(
                gateway: $gatewayName,
                eventId: $eventId,
                eventType: 'callback.unmatched',
                payloadHash: $payloadHash,
                status: PaymentWebhookEvent::STATUS_FAILED,
                paymentId: null,
                errorMessage: $error,
                payload: $postData
            );

            return PaymentWebhookResult::failed(
                message: $error,
                paymentId: null,
                paymentNumber: null,
                eventId: $eventId,
                metadata: $verificationResponse->getRawPayload()
            );
        }

        // 6. Handle Out-Of-Order / Already Completed State
        if ($payment->isCompleted()) {
            $this->recordEvent(
                gateway: $gatewayName,
                eventId: $eventId,
                eventType: 'callback.success',
                payloadHash: $payloadHash,
                status: PaymentWebhookEvent::STATUS_PROCESSED,
                paymentId: $payment->getId(),
                errorMessage: null,
                payload: $postData
            );

            return PaymentWebhookResult::alreadyCompleted(
                paymentId: (int) $payment->getId(),
                paymentNumber: $payment->getPaymentNumber(),
                message: 'Payment was already completed prior to this callback notification.',
                eventId: $eventId,
                metadata: $verificationResponse->getRawPayload()
            );
        }

        // 7. Settle Payment & Allocate Funds
        $txRef = $verificationResponse->getPaymentTransactionId() 
            ?? $verificationResponse->getPaymentId() 
            ?? $token;

        $completedPayment = $this->paymentService->completePaymentFromGateway(
            paymentId: (int) $payment->getId(),
            transactionRef: $txRef,
            feeMinor: $verificationResponse->getFeeMinor(),
            paidAt: date('Y-m-d H:i:s')
        );

        // 8. Record Successful Event Audit
        $this->recordEvent(
            gateway: $gatewayName,
            eventId: $eventId,
            eventType: 'callback.success',
            payloadHash: $payloadHash,
            status: PaymentWebhookEvent::STATUS_PROCESSED,
            paymentId: $completedPayment->getId(),
            errorMessage: null,
            payload: $postData
        );

        return PaymentWebhookResult::processed(
            paymentId: (int) $completedPayment->getId(),
            paymentNumber: $completedPayment->getPaymentNumber(),
            message: 'Payment verified and successfully settled.',
            eventId: $eventId,
            metadata: [
                'card_association' => $verificationResponse->getCardAssociation(),
                'card_family' => $verificationResponse->getCardFamily(),
                'installments' => $verificationResponse->getInstallments(),
                'transaction_reference' => $txRef,
            ]
        );
    }

    /**
     * Generic webhook processor for payment gateways with custom event identifiers.
     *
     * @param array<string, mixed> $payload
     */
    public function handleGenericWebhook(
        PaymentGatewayInterface $gateway,
        string $eventId,
        string $eventType,
        array $payload,
        ?string $rawPayload = null
    ): PaymentWebhookResult {
        $gatewayName = $gateway->getIdentifier();
        $payloadHash = hash('sha256', $rawPayload ?? (string) json_encode($payload));

        // Idempotency check
        $existingEvent = $this->findEvent($gatewayName, $eventId);
        if ($existingEvent !== null && $existingEvent['status'] === PaymentWebhookEvent::STATUS_PROCESSED) {
            $paymentId = $existingEvent['payment_id'] !== null ? (int) $existingEvent['payment_id'] : null;
            $payment = $paymentId !== null ? $this->paymentService->findPaymentById($paymentId) : null;

            return PaymentWebhookResult::duplicate(
                paymentId: $paymentId ?? 0,
                paymentNumber: $payment ? $payment->getPaymentNumber() : '',
                message: 'Webhook event already processed (idempotent duplicate).',
                eventId: $eventId
            );
        }

        // Process depending on event type
        if ($eventType === 'payment.success') {
            $paymentNumber = (string) ($payload['payment_number'] ?? '');
            $payment = $this->paymentService->findPaymentByNumber($paymentNumber);

            if ($payment === null) {
                $err = "Payment {$paymentNumber} not found.";
                $this->recordEvent($gatewayName, $eventId, $eventType, $payloadHash, PaymentWebhookEvent::STATUS_FAILED, null, $err, $payload);
                return PaymentWebhookResult::failed($err, null, $paymentNumber, $eventId);
            }

            if ($payment->isCompleted()) {
                $this->recordEvent($gatewayName, $eventId, $eventType, $payloadHash, PaymentWebhookEvent::STATUS_PROCESSED, $payment->getId(), null, $payload);
                return PaymentWebhookResult::alreadyCompleted((int) $payment->getId(), $payment->getPaymentNumber(), 'Payment already completed.', $eventId);
            }

            $txRef = (string) ($payload['transaction_reference'] ?? $eventId);
            $feeMinor = (int) ($payload['fee_minor'] ?? 0);

            $completed = $this->paymentService->completePaymentFromGateway((int) $payment->getId(), $txRef, $feeMinor);
            $this->recordEvent($gatewayName, $eventId, $eventType, $payloadHash, PaymentWebhookEvent::STATUS_PROCESSED, $completed->getId(), null, $payload);

            return PaymentWebhookResult::processed((int) $completed->getId(), $completed->getPaymentNumber(), 'Webhook payment processed.', $eventId);
        }

        if ($eventType === 'payment.failed') {
            $paymentNumber = (string) ($payload['payment_number'] ?? '');
            $payment = $this->paymentService->findPaymentByNumber($paymentNumber);
            $reason = (string) ($payload['reason'] ?? 'Payment failed by gateway webhook.');

            if ($payment !== null && $payment->isPending()) {
                $this->paymentService->failPaymentFromGateway((int) $payment->getId(), $reason);
            }

            $this->recordEvent($gatewayName, $eventId, $eventType, $payloadHash, PaymentWebhookEvent::STATUS_FAILED, $payment?->getId(), $reason, $payload);
            return PaymentWebhookResult::failed($reason, $payment?->getId(), $paymentNumber, $eventId);
        }

        $this->recordEvent($gatewayName, $eventId, $eventType, $payloadHash, PaymentWebhookEvent::STATUS_PROCESSED, null, 'Ignored unsupported event type', $payload);
        return PaymentWebhookResult::failed("Unsupported event type {$eventType}", null, null, $eventId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findEvent(string $gateway, string $eventId): ?array
    {
        return $this->db->selectOne(
            sprintf('SELECT * FROM %s WHERE gateway = ? AND event_id = ?', $this->webhookEventsTable),
            [$gateway, $eventId]
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function recordEvent(
        string $gateway,
        string $eventId,
        string $eventType,
        string $payloadHash,
        string $status,
        ?int $paymentId,
        ?string $errorMessage,
        array $payload
    ): void {
        $now = date('Y-m-d H:i:s');
        $processedAt = $status === PaymentWebhookEvent::STATUS_PROCESSED ? $now : null;

        $sql = sprintf(
            'INSERT INTO %s (gateway, event_id, event_type, payload_hash, status, payment_id, error_message, payload_json, processed_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $this->webhookEventsTable
        );

        $this->db->statement($sql, [
            $gateway,
            $eventId,
            $eventType,
            $payloadHash,
            $status,
            $paymentId,
            $errorMessage,
            json_encode($payload),
            $processedAt,
            $now,
        ]);
    }
}
