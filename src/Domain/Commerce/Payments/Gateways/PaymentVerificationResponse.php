<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Payments\Gateways;

final class PaymentVerificationResponse
{
    /**
     * @param array<string, mixed> $rawPayload
     */
    public function __construct(
        private bool $success,
        private ?string $paymentId = null,
        private ?string $paymentTransactionId = null,
        private int $paidAmountMinor = 0,
        private string $currency = 'TRY',
        private int $feeMinor = 0,
        private ?string $cardAssociation = null,
        private ?string $cardFamily = null,
        private int $installments = 1,
        private ?string $errorMessage = null,
        private array $rawPayload = []
    ) {
    }

    public static function successful(
        string $paymentId,
        string $paymentTransactionId,
        int $paidAmountMinor,
        string $currency = 'TRY',
        int $feeMinor = 0,
        ?string $cardAssociation = null,
        ?string $cardFamily = null,
        int $installments = 1,
        array $rawPayload = []
    ): self {
        return new self(
            success: true,
            paymentId: $paymentId,
            paymentTransactionId: $paymentTransactionId,
            paidAmountMinor: $paidAmountMinor,
            currency: $currency,
            feeMinor: $feeMinor,
            cardAssociation: $cardAssociation,
            cardFamily: $cardFamily,
            installments: $installments,
            errorMessage: null,
            rawPayload: $rawPayload
        );
    }

    public static function failure(string $errorMessage, array $rawPayload = []): self
    {
        return new self(
            success: false,
            errorMessage: $errorMessage,
            rawPayload: $rawPayload
        );
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getPaymentId(): ?string
    {
        return $this->paymentId;
    }

    public function getPaymentTransactionId(): ?string
    {
        return $this->paymentTransactionId;
    }

    public function getPaidAmountMinor(): int
    {
        return $this->paidAmountMinor;
    }

    public function getCurrency(): string
    {
        return strtoupper($this->currency);
    }

    public function getFeeMinor(): int
    {
        return $this->feeMinor;
    }

    public function getCardAssociation(): ?string
    {
        return $this->cardAssociation;
    }

    public function getCardFamily(): ?string
    {
        return $this->cardFamily;
    }

    public function getInstallments(): int
    {
        return $this->installments;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRawPayload(): array
    {
        return $this->rawPayload;
    }
}
