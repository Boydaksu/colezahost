<?php

declare(strict_types=1);

namespace Coleza\Foundation\Exceptions;

class ConcurrencyException extends AppException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message = 'The resource has been modified concurrently by another process.',
        string $errorCode = 'CONCURRENCY_CONFLICT',
        array $context = []
    ) {
        parent::__construct(
            message: $message,
            errorCode: $errorCode,
            httpStatusCode: 409,
            context: $context
        );
    }
}
