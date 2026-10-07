<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Payments\Gateways;

final class PaymentRefundResponse
{
    /**
     * @param array<string, mixed> $rawPayload
     */
    public function __construct(
        private bool $success,
        private ?string $refundId = null,
        private int $refundedAmountMinor = 0,
        private ?string $errorCode = null,
        private ?string $errorMessage = null,
        private array $rawPayload = []
    ) {
    }

    public static function successful(string $refundId, int $refundedAmountMinor, array $rawPayload = []): self
    {
        return new self(
            success: true,
            refundId: $refundId,
            refundedAmountMinor: $refundedAmountMinor,
            errorCode: null,
            errorMessage: null,
            rawPayload: $rawPayload
        );
    }

    public static function failure(string $errorMessage, ?string $errorCode = null, array $rawPayload = []): self
    {
        return new self(
            success: false,
            refundId: null,
            refundedAmountMinor: 0,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            rawPayload: $rawPayload
        );
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getRefundId(): ?string
    {
        return $this->refundId;
    }

    public function getRefundedAmountMinor(): int
    {
        return $this->refundedAmountMinor;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
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
