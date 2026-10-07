<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Payments\Gateways;

final class PaymentWebhookEvent
{
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_DUPLICATE = 'duplicate';
    public const STATUS_FAILED = 'failed';

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private ?int $id,
        private string $gateway,
        private string $eventId,
        private string $eventType,
        private string $payloadHash,
        private string $status,
        private ?int $paymentId = null,
        private ?string $errorMessage = null,
        private array $payload = [],
        private ?string $processedAt = null,
        private ?string $createdAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGateway(): string
    {
        return $this->gateway;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getPayloadHash(): string
    {
        return $this->payloadHash;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getPaymentId(): ?int
    {
        return $this->paymentId;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getProcessedAt(): ?string
    {
        return $this->processedAt;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }
}
