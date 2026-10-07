<?php

declare(strict_types=1);

namespace Coleza\Foundation\Exceptions;

final class AuthenticationException extends AppException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message = 'Unauthenticated.',
        string $errorCode = 'UNAUTHENTICATED',
        array $context = []
    ) {
        parent::__construct(
            message: $message,
            errorCode: $errorCode,
            httpStatusCode: 401,
            context: $context
        );
    }
}
