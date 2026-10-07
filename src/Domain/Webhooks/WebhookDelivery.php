<?php

declare(strict_types=1);

namespace Coleza\Domain\Webhooks;

final class WebhookDelivery
{
    public function __construct(
        private ?int $id,
        private int $endpointId,
        private string $eventId,
        private string $eventType,
        private string $payload,
        private int $attemptNumber = 1,
        private ?int $statusCode = null,
        private ?string $responseBody = null,
        private bool $isSuccess = false,
        private ?string $error = null,
        private ?string $nextRetryAt = null,
        private int $maxAttempts = 5,
        private ?string $createdAt = null,
        private ?string $completedAt = null
    ) {
        $this->createdAt ??= date('c');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEndpointId(): int
    {
        return $this->endpointId;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function getAttemptNumber(): int
    {
        return $this->attemptNumber;
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }

    public function isSuccess(): bool
    {
        return $this->isSuccess;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getNextRetryAt(): ?string
    {
        return $this->nextRetryAt;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt ?? date('c');
    }

    public function getCompletedAt(): ?string
    {
        return $this->completedAt;
    }

    public function canRetry(): bool
    {
        return !$this->isSuccess && $this->attemptNumber < $this->maxAttempts;
    }

    /**
     * Calculates exponential backoff delay in seconds for next retry.
     */
    public function calculateBackoffSeconds(): int
    {
        // 1st retry: 60s, 2nd: 300s (5m), 3rd: 1800s (30m), 4th: 7200s (2h), etc.
        return match ($this->attemptNumber) {
            1 => 60,
            2 => 300,
            3 => 1800,
            4 => 7200,
            default => 86400,
        };
    }
}
