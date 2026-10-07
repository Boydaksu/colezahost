<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Payments;

final class RefundAttempt
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private ?int $id,
        private int $paymentId,
        private int $amountMinor,
        private string $reason,
        private string $gateway,
        private string $status,
        private ?string $errorCode = null,
        private ?string $errorMessage = null,
        private ?string $transactionReference = null,
        private array $metadata = [],
        private ?string $attemptedAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPaymentId(): int
    {
        return $this->paymentId;
    }

    public function getAmountMinor(): int
    {
        return $this->amountMinor;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getGateway(): string
    {
        return $this->gateway;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getTransactionReference(): ?string
    {
        return $this->transactionReference;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getAttemptedAt(): ?string
    {
        return $this->attemptedAt;
    }
}
