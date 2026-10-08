<?php

declare(strict_types=1);

namespace Coleza\Domain\Provisioning\Reconciliation;

final class ReconciliationResult
{
    /**
     * @param array<string, mixed> $remoteData
     */
    public function __construct(
        private string $status,
        private string $operationUuid,
        private int $serviceId,
        private bool $accountFound,
        private array $remoteData = [],
        private string $message = ''
    ) {
    }

    /**
     * @param array<string, mixed> $remoteData
     */
    public static function exists(
        string $operationUuid,
        int $serviceId,
        array $remoteData = [],
        string $message = 'Remote account exists and is healthy; reconciled as success.'
    ): self {
        return new self(
            status: ReconciliationStatus::RECONCILED_EXISTS,
            operationUuid: $operationUuid,
            serviceId: $serviceId,
            accountFound: true,
            remoteData: $remoteData,
            message: $message
        );
    }

    public static function notFound(
        string $operationUuid,
        int $serviceId,
        string $message = 'Remote account not found; safe to re-execute.'
    ): self {
        return new self(
            status: ReconciliationStatus::RECONCILED_NOT_FOUND,
            operationUuid: $operationUuid,
            serviceId: $serviceId,
            accountFound: false,
            remoteData: [],
            message: $message
        );
    }

    public static function unreachable(
        string $operationUuid,
        int $serviceId,
        string $message = 'Remote server remains unreachable; probe timed out.'
    ): self {
        return new self(
            status: ReconciliationStatus::RECONCILED_UNREACHABLE,
            operationUuid: $operationUuid,
            serviceId: $serviceId,
            accountFound: false,
            remoteData: [],
            message: $message
        );
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getOperationUuid(): string
    {
        return $this->operationUuid;
    }

    public function getServiceId(): int
    {
        return $this->serviceId;
    }

    public function isAccountFound(): bool
    {
        return $this->accountFound;
    }

    public function isExists(): bool
    {
        return $this->status === ReconciliationStatus::RECONCILED_EXISTS;
    }

    public function isNotFound(): bool
    {
        return $this->status === ReconciliationStatus::RECONCILED_NOT_FOUND;
    }

    public function isUnreachable(): bool
    {
        return $this->status === ReconciliationStatus::RECONCILED_UNREACHABLE;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRemoteData(): array
    {
        return $this->remoteData;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'operation_uuid' => $this->operationUuid,
            'service_id' => $this->serviceId,
            'account_found' => $this->accountFound,
            'remote_data' => $this->remoteData,
            'message' => $this->message,
        ];
    }
}
