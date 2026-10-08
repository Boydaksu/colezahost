<?php

declare(strict_types=1);

namespace Coleza\Domain\Providers\DTO;

final class ProviderOperationResult
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $rawResponse
     */
    public function __construct(
        private bool $success,
        private string $operationType,
        private string $message,
        private ?string $externalIdentifier = null,
        private array $data = [],
        private ?string $errorCode = null,
        private ?array $rawResponse = null,
        private ?string $executedAt = null
    ) {
        if ($this->executedAt === null) {
            $this->executedAt = date('Y-m-d H:i:s');
        }
    }

    /**
     * Factory for successful provider operation outcomes.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $rawResponse
     */
    public static function success(
        string $operationType,
        string $message,
        ?string $externalIdentifier = null,
        array $data = [],
        ?array $rawResponse = null
    ): self {
        return new self(
            success: true,
            operationType: $operationType,
            message: $message,
            externalIdentifier: $externalIdentifier,
            data: $data,
            errorCode: null,
            rawResponse: $rawResponse
        );
    }

    /**
     * Factory for failed provider operation outcomes.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $rawResponse
     */
    public static function failure(
        string $operationType,
        string $message,
        string $errorCode = 'OPERATION_FAILED',
        array $data = [],
        ?array $rawResponse = null
    ): self {
        return new self(
            success: false,
            operationType: $operationType,
            message: $message,
            externalIdentifier: null,
            data: $data,
            errorCode: $errorCode,
            rawResponse: $rawResponse
        );
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function isFailure(): bool
    {
        return !$this->success;
    }

    public function getOperationType(): string
    {
        return $this->operationType;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getExternalIdentifier(): ?string
    {
        return $this->externalIdentifier;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRawResponse(): ?array
    {
        return $this->rawResponse;
    }

    public function getExecutedAt(): string
    {
        return $this->executedAt ?? date('Y-m-d H:i:s');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'operation_type' => $this->operationType,
            'message' => $this->message,
            'external_identifier' => $this->externalIdentifier,
            'data' => $this->data,
            'error_code' => $this->errorCode,
            'raw_response' => $this->rawResponse,
            'executed_at' => $this->executedAt,
        ];
    }
}
