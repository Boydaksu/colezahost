<?php

declare(strict_types=1);

namespace Coleza\Domain\Domains\Registrar;

final class RegistrarOperationResult
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly bool $isSuccessful,
        private readonly string $operation,
        private readonly string $domain,
        private readonly ?string $remoteTransactionId = null,
        private readonly ?string $expirationDate = null,
        private readonly ?string $errorMessage = null,
        private readonly ?string $errorCode = null,
        private readonly array $metadata = []
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $rawResponse
     */
    public static function success(
        string $operation,
        string $domain,
        ?string $remoteTransactionId = null,
        ?string $expirationDate = null,
        array $metadata = [],
        array $rawResponse = []
    ): self {
        if (!empty($rawResponse)) {
            $metadata['raw_response'] = $rawResponse;
        }

        return new self(
            isSuccessful: true,
            operation: $operation,
            domain: $domain,
            remoteTransactionId: $remoteTransactionId,
            expirationDate: $expirationDate,
            errorMessage: null,
            errorCode: null,
            metadata: $metadata
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function failed(
        string $operation,
        string $domain,
        string $errorMessage,
        ?string $errorCode = null,
        array $metadata = []
    ): self {
        return new self(
            isSuccessful: false,
            operation: $operation,
            domain: $domain,
            remoteTransactionId: null,
            expirationDate: null,
            errorMessage: $errorMessage,
            errorCode: $errorCode,
            metadata: $metadata
        );
    }

    /**
     * Alias for failure result with optional raw response.
     *
     * @param array<string, mixed> $rawResponse
     * @param array<string, mixed> $metadata
     */
    public static function failure(
        string $operation,
        string $domain,
        ?string $errorCode = null,
        string $errorMessage = 'Operation failed',
        array $rawResponse = [],
        array $metadata = []
    ): self {
        if (!empty($rawResponse)) {
            $metadata['raw_response'] = $rawResponse;
        }

        return new self(
            isSuccessful: false,
            operation: $operation,
            domain: $domain,
            remoteTransactionId: null,
            expirationDate: null,
            errorMessage: $errorMessage,
            errorCode: $errorCode,
            metadata: $metadata
        );
    }

    public function isSuccessful(): bool
    {
        return $this->isSuccessful;
    }

    public function getOperation(): string
    {
        return $this->operation;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function getRemoteTransactionId(): ?string
    {
        return $this->remoteTransactionId;
    }

    public function getExpirationDate(): ?string
    {
        return $this->expirationDate;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRawResponse(): ?array
    {
        if (isset($this->metadata['raw_response']) && is_array($this->metadata['raw_response'])) {
            return $this->metadata['raw_response'];
        }
        return !empty($this->metadata) ? $this->metadata : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'is_successful' => $this->isSuccessful,
            'operation' => $this->operation,
            'domain' => $this->domain,
            'remote_transaction_id' => $this->remoteTransactionId,
            'expiration_date' => $this->expirationDate,
            'error_message' => $this->errorMessage,
            'error_code' => $this->errorCode,
            'metadata' => $this->metadata,
        ];
    }
}
