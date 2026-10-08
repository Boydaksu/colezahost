<?php

declare(strict_types=1);

namespace Coleza\Domain\Provisioning\Entities;

final class ProvisioningAuditLog
{
    /**
     * @param array<string, mixed>|null $rawResponse
     */
    public function __construct(
        private ?int $id,
        private int $operationId,
        private int $serviceId,
        private string $action,
        private int $attemptNumber,
        private bool $success,
        private ?string $errorCategory = null,
        private ?string $errorCode = null,
        private ?string $clientSafeMessage = null,
        private ?string $adminActionableMessage = null,
        private ?array $rawResponse = null,
        private ?string $loggedAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOperationId(): int
    {
        return $this->operationId;
    }

    public function getServiceId(): int
    {
        return $this->serviceId;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getAttemptNumber(): int
    {
        return $this->attemptNumber;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getErrorCategory(): ?string
    {
        return $this->errorCategory;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getClientSafeMessage(): ?string
    {
        return $this->clientSafeMessage;
    }

    public function getAdminActionableMessage(): ?string
    {
        return $this->adminActionableMessage;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRawResponse(): ?array
    {
        return $this->rawResponse;
    }

    public function getLoggedAt(): ?string
    {
        return $this->loggedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'operation_id' => $this->operationId,
            'service_id' => $this->serviceId,
            'action' => $this->action,
            'attempt_number' => $this->attemptNumber,
            'success' => $this->success,
            'error_category' => $this->errorCategory,
            'error_code' => $this->errorCode,
            'client_safe_message' => $this->clientSafeMessage,
            'admin_actionable_message' => $this->adminActionableMessage,
            'raw_response' => $this->rawResponse,
            'logged_at' => $this->loggedAt,
        ];
    }
}
