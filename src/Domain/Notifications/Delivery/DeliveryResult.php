<?php

declare(strict_types=1);

namespace Coleza\Domain\Notifications\Delivery;

final class DeliveryResult
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private bool $success,
        private ?string $messageId,
        private string $transport,
        private ?string $error = null,
        private ?string $sentAt = null,
        private array $metadata = []
    ) {
        $this->sentAt ??= date('c');
    }

    public static function success(string $messageId, string $transport, array $metadata = []): self
    {
        return new self(
            success: true,
            messageId: $messageId,
            transport: $transport,
            error: null,
            sentAt: date('c'),
            metadata: $metadata
        );
    }

    public static function failure(string $error, string $transport, ?string $messageId = null, array $metadata = []): self
    {
        return new self(
            success: false,
            messageId: $messageId,
            transport: $transport,
            error: $error,
            sentAt: date('c'),
            metadata: $metadata
        );
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    public function getTransport(): string
    {
        return $this->transport;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getSentAt(): string
    {
        return $this->sentAt ?? date('c');
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
