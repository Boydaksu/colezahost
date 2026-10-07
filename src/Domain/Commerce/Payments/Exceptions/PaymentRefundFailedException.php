<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Payments\Exceptions;

use RuntimeException;

final class PaymentRefundFailedException extends RuntimeException
{
    /**
     * @param array<string, mixed> $rawDetails
     */
    public function __construct(
        string $message,
        private int $paymentId,
        private int $amountMinor,
        private ?string $errorCode = null,
        private array $rawDetails = []
    ) {
        parent::__construct($message);
    }

    public function getPaymentId(): int
    {
        return $this->paymentId;
    }

    public function getAmountMinor(): int
    {
        return $this->amountMinor;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRawDetails(): array
    {
        return $this->rawDetails;
    }
}
