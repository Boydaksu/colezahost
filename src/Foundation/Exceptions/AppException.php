<?php

declare(strict_types=1);

namespace Coleza\Foundation\Exceptions;

use Exception;
use Throwable;

class AppException extends Exception
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message,
        protected string $errorCode = 'INTERNAL_ERROR',
        protected int $httpStatusCode = 500,
        protected array $context = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $httpStatusCode, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Formats the exception into a client-safe response payload.
     *
     * @return array{error: string, code: string, details?: array<string, mixed>}
     */
    public function toResponseArray(bool $includeDetails = false): array
    {
        $payload = [
            'error' => $this->getMessage(),
            'code' => $this->errorCode,
        ];

        if ($includeDetails && count($this->context) > 0) {
            $payload['details'] = $this->context;
        }

        return $payload;
    }
}
