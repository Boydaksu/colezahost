<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Services;

final class ServiceCancellationRequest
{
    public const TYPE_IMMEDIATE = 'immediate';
    public const TYPE_END_OF_PERIOD = 'end_of_period';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_REVOKED = 'revoked';

    public function __construct(
        private ?int $id,
        private int $serviceId,
        private int $userId,
        private string $type = self::TYPE_END_OF_PERIOD,
        private string $reason = '',
        private string $status = self::STATUS_PENDING,
        private ?string $requestedAt = null,
        private ?string $processedAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getServiceId(): int
    {
        return $this->serviceId;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isImmediate(): bool
    {
        return $this->type === self::TYPE_IMMEDIATE;
    }

    public function isEndOfPeriod(): bool
    {
        return $this->type === self::TYPE_END_OF_PERIOD;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isProcessed(): bool
    {
        return $this->status === self::STATUS_PROCESSED;
    }

    public function isRevoked(): bool
    {
        return $this->status === self::STATUS_REVOKED;
    }

    public function getRequestedAt(): ?string
    {
        return $this->requestedAt;
    }

    public function getProcessedAt(): ?string
    {
        return $this->processedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'service_id' => $this->serviceId,
            'user_id' => $this->userId,
            'type' => $this->type,
            'reason' => $this->reason,
            'status' => $this->status,
            'requested_at' => $this->requestedAt,
            'processed_at' => $this->processedAt,
        ];
    }
}
