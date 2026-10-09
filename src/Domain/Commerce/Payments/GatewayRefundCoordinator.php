<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Payments;

use Coleza\Domain\Commerce\Payments\Exceptions\PaymentRefundFailedException;
use Coleza\Domain\Commerce\Payments\Gateways\PaymentGatewayInterface;
use Coleza\Domain\Commerce\Payments\Gateways\PaymentRefundRequest;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use RuntimeException;
use Throwable;

/** Commits a reservation before remote effects; uncertain outcomes keep their reservation. */
final class GatewayRefundCoordinator
{
    public function __construct(private Connection $db, private PaymentService $payments) {}

    public function refund(int $paymentId, int $amount, string $reason, PaymentGatewayInterface $gateway, array $metadata): Refund
    {
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Gateway refunds require a separately committed reservation.');
        }
        if (trim($reason) === '') {
            throw new ValidationException(['reason' => 'Refund reason is required.'], 'Reason missing');
        }
        $requestId = bin2hex(random_bytes(32));
        $payment = $this->db->transaction(function () use ($paymentId, $amount, $gateway, $requestId): Payment {
            $this->db->lockRow('payments', $paymentId);
            $payment = $this->payments->findPaymentById($paymentId) ?? throw new RuntimeException('Payment not found.');
            if (!$payment->isCompleted() && !$payment->isPartiallyRefunded()) {
                throw new ValidationException(['payment' => 'Can only refund completed or partially refunded payments.'], 'Invalid payment status for refund');
            }
            if ($this->payments->reservedRefundAmount($paymentId) > 0) {
                throw new ValidationException(['refund' => 'An outstanding provider refund must be reconciled first.'], 'Refund reservation unresolved');
            }
            if ($amount <= 0 || $amount > $payment->getRefundableAmountMinor()) {
                throw new ValidationException(['amount_minor' => 'Refund exceeds unreserved refundable balance.'], 'Excessive refund amount');
            }
            if ($payment->getTransactionReference() === null || trim($payment->getTransactionReference()) === '') {
                throw new ValidationException(['transaction_reference' => 'Payment has no transaction reference.'], 'Missing transaction reference');
            }
            if ($payment->getPaymentMethod() !== $gateway->getIdentifier()) {
                throw new ValidationException(['gateway' => 'Refund gateway must match the original payment.'], 'Gateway mismatch');
            }
            $this->db->statement('INSERT INTO gateway_refund_reservations (request_id, payment_id, amount_minor, status, gateway)
                VALUES (?, ?, ?, "processing", ?)', [$requestId, $paymentId, $amount, $gateway->getIdentifier()]);
            return $payment;
        });
        try {
            $response = $gateway->refund(new PaymentRefundRequest((string) $payment->getTransactionReference(), $amount,
                $payment->getCurrencyCode(), $reason, ['request_id' => $requestId]));
        } catch (Throwable $error) {
            $this->failed($requestId, $paymentId, $amount, $reason, $gateway->getIdentifier(), 'UNKNOWN', 'Provider result is unknown.', true);
            throw new PaymentRefundFailedException('Provider result is unknown; reconciliation required.', $paymentId, $amount, 'UNKNOWN');
        }
        if (!$response->isSuccess()) {
            $code = $response->getErrorCode() ?? 'UNKNOWN';
            $unknown = in_array($code, ['UNKNOWN', 'EXCEPTION'], true);
            $message = $response->getErrorMessage() ?? 'Refund failed.';
            $this->failed($requestId, $paymentId, $amount, $reason, $gateway->getIdentifier(), $code, $message, $unknown);
            throw new PaymentRefundFailedException($message, $paymentId, $amount, $code, $response->getRawPayload());
        }
        if ($response->getRefundedAmountMinor() !== $amount || trim((string) $response->getRefundId()) === '') {
            $this->failed($requestId, $paymentId, $amount, $reason, $gateway->getIdentifier(), 'UNKNOWN', 'Refund response mismatch.', true);
            throw new PaymentRefundFailedException('Refund response mismatch; reconciliation required.', $paymentId, $amount, 'UNKNOWN');
        }
        // Retain provider proof even if local invoice/ledger application subsequently fails.
        $this->db->statement('UPDATE gateway_refund_reservations SET status = "verified", provider_reference = ? WHERE request_id = ?',
            [$response->getRefundId(), $requestId]);
        return $this->payments->applyReservedGatewayRefund($requestId, $reason,
            array_merge($metadata, ['gateway_response' => $response->getRawPayload()]));
    }

    private function failed(string $id, int $payment, int $amount, string $reason, string $gateway, string $code, string $message, bool $unknown): void
    {
        $this->db->transaction(function () use ($id, $payment, $amount, $reason, $gateway, $code, $message, $unknown): void {
            $this->db->lockRow('payments', $payment);
            $this->db->statement('UPDATE gateway_refund_reservations SET status = ? WHERE request_id = ?', [$unknown ? 'unknown' : 'failed', $id]);
            $this->payments->recordRefundAttempt($payment, $amount, $reason, $gateway, $unknown ? 'unknown' : RefundAttempt::STATUS_FAILED,
                $code, $message);
        });
    }
}
