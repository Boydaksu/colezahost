<?php

declare(strict_types=1);

namespace Coleza\Domain\Domains\Registrar\Reconciliation;

use DateTimeImmutable;

final class DomainOperation
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_UNCERTAIN = 'uncertain';

    public const TYPE_REGISTER = 'register';
    public const TYPE_RENEW = 'renew';
    public const TYPE_TRANSFER = 'transfer';
    public const TYPE_UPDATE_NAMESERVERS = 'update_nameservers';
    public const TYPE_SET_LOCK = 'set_lock';
    public const TYPE_UPDATE_CONTACTS = 'update_contacts';

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $result
     */
    public function __construct(
        private readonly ?int $id,
        private readonly int $domainId,
        private readonly string $operationType,
        private readonly string $idempotencyKey,
        private readonly string $status,
        private readonly ?string $remoteTransactionId = null,
        private readonly ?string $errorCode = null,
        private readonly ?string $errorMessage = null,
        private readonly array $payload = [],
        private readonly array $result = [],
        private readonly int $attempts = 1,
        private readonly ?DateTimeImmutable $lastAttemptAt = null,
        private readonly ?DateTimeImmutable $reconciledAt = null,
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getOperationType(): string
    {
        return $this->operationType;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isSucceeded(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isUncertain(): bool
    {
        return $this->status === self::STATUS_UNCERTAIN;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function getRemoteTransactionId(): ?string
    {
        return $this->remoteTransactionId;
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
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function getResult(): array
    {
        return $this->result;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getLastAttemptAt(): ?DateTimeImmutable
    {
        return $this->lastAttemptAt;
    }

    public function getReconciledAt(): ?DateTimeImmutable
    {
        return $this->reconciledAt;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'domain_id' => $this->domainId,
            'operation_type' => $this->operationType,
            'idempotency_key' => $this->idempotencyKey,
            'status' => $this->status,
            'remote_transaction_id' => $this->remoteTransactionId,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
            'payload' => $this->payload,
            'result' => $this->result,
            'attempts' => $this->attempts,
            'last_attempt_at' => $this->lastAttemptAt?->format(DateTimeImmutable::ATOM),
            'reconciled_at' => $this->reconciledAt?->format(DateTimeImmutable::ATOM),
            'created_at' => $this->createdAt?->format(DateTimeImmutable::ATOM),
            'updated_at' => $this->updatedAt?->format(DateTimeImmutable::ATOM),
        ];
    }
}
