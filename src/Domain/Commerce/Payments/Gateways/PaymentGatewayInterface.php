<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Payments\Gateways;

interface PaymentGatewayInterface
{
    public function getGatewayId(): string;

    public function getDisplayName(): string;

    public function initializeCheckout(PaymentCheckoutRequest $request): PaymentCheckoutResponse;

    public function verifyPayment(PaymentVerificationRequest $request): PaymentVerificationResponse;

    public function refund(PaymentRefundRequest $request): PaymentRefundResponse;
}
