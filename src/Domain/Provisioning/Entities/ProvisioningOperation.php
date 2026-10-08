<?php

declare(strict_types=1);

namespace Coleza\Domain\Provisioning\Entities;

use Coleza\Domain\Provisioning\Classification\ProvisioningErrorClassification;

final class ProvisioningOperation
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_RETRYING = 'retrying';

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $resultData
     */
    public function __construct(
        private ?int $id,
        private string $operationUuid,
        private int $serviceId,
        private ?int $serverId,
        private string $providerSlug,
        private string $action,
        private string $status = self::STATUS_QUEUED,
        private int $attemptCount = 0,
        private int $maxAttempts = 3,
        private ?string $nextAttemptAt = null,
        private ?ProvisioningErrorClassification $errorClassification = null,
        private array $payload = [],
        private array $resultData = [],
        private string $correlationId = '',
        private ?string $createdAt = null,
        private ?string $updatedAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOperationUuid(): string
    {
        return $this->operationUuid;
    }

    public function getServiceId(): int
    {
        return $this->serviceId;
    }

    public function getServerId(): ?int
    {
        return $this->serverId;
    }

    public function getProviderSlug(): string
    {
        return $this->providerSlug;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getAttemptCount(): int
    {
        return $this->attemptCount;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function getNextAttemptAt(): ?string
    {
        return $this->nextAttemptAt;
    }

    public function getErrorClassification(): ?ProvisioningErrorClassification
    {
        return $this->errorClassification;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function getResultData(): array
    {
        return $this->resultData;
    }

    public function getCorrelationId(): string
    {
        return $this->correlationId;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    public function isQueued(): bool
    {
        return $this->status === self::STATUS_QUEUED;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isRetrying(): bool
    {
        return $this->status === self::STATUS_RETRYING;
    }

    public function canRetry(): bool
    {
        return $this->attemptCount < $this->maxAttempts
            && $this->errorClassification !== null
            && $this->errorClassification->isRetryable();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'operation_uuid' => $this->operationUuid,
            'service_id' => $this->serviceId,
            'server_id' => $this->serverId,
            'provider_slug' => $this->providerSlug,
            'action' => $this->action,
            'status' => $this->status,
            'attempt_count' => $this->attemptCount,
            'max_attempts' => $this->maxAttempts,
            'next_attempt_at' => $this->nextAttemptAt,
            'error_classification' => $this->errorClassification?->toArray(),
            'payload' => $this->payload,
            'result_data' => $this->resultData,
            'correlation_id' => $this->correlationId,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
