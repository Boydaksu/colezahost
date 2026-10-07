<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Payments\Gateways;

final class PaymentWebhookResult
{
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_DUPLICATE = 'duplicate';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ALREADY_COMPLETED = 'already_completed';

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private bool $success,
        private string $status,
        private string $message,
        private ?int $paymentId = null,
        private ?string $paymentNumber = null,
        private ?string $eventId = null,
        private array $metadata = []
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function processed(
        int $paymentId,
        string $paymentNumber,
        string $message = 'Payment successfully settled from webhook/callback.',
        ?string $eventId = null,
        array $metadata = []
    ): self {
        return new self(
            success: true,
            status: self::STATUS_PROCESSED,
            message: $message,
            paymentId: $paymentId,
            paymentNumber: $paymentNumber,
            eventId: $eventId,
            metadata: $metadata
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function duplicate(
        int $paymentId,
        string $paymentNumber,
        string $message = 'Event already processed (idempotent duplicate).',
        ?string $eventId = null,
        array $metadata = []
    ): self {
        return new self(
            success: true,
            status: self::STATUS_DUPLICATE,
            message: $message,
            paymentId: $paymentId,
            paymentNumber: $paymentNumber,
            eventId: $eventId,
            metadata: $metadata
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function alreadyCompleted(
        int $paymentId,
        string $paymentNumber,
        string $message = 'Payment is already in completed state.',
        ?string $eventId = null,
        array $metadata = []
    ): self {
        return new self(
            success: true,
            status: self::STATUS_ALREADY_COMPLETED,
            message: $message,
            paymentId: $paymentId,
            paymentNumber: $paymentNumber,
            eventId: $eventId,
            metadata: $metadata
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function failed(
        string $message,
        ?int $paymentId = null,
        ?string $paymentNumber = null,
        ?string $eventId = null,
        array $metadata = []
    ): self {
        return new self(
            success: false,
            status: self::STATUS_FAILED,
            message: $message,
            paymentId: $paymentId,
            paymentNumber: $paymentNumber,
            eventId: $eventId,
            metadata: $metadata
        );
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function isDuplicate(): bool
    {
        return $this->status === self::STATUS_DUPLICATE;
    }

    public function isProcessed(): bool
    {
        return $this->status === self::STATUS_PROCESSED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getPaymentId(): ?int
    {
        return $this->paymentId;
    }

    public function getPaymentNumber(): ?string
    {
        return $this->paymentNumber;
    }

    public function getEventId(): ?string
    {
        return $this->eventId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
