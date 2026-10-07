<?php

declare(strict_types=1);

namespace Coleza\Foundation\Exceptions;

final class AuthorizationException extends AppException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message = 'Forbidden: You do not have permission to perform this action.',
        string $errorCode = 'PERMISSION_DENIED',
        array $context = []
    ) {
        parent::__construct(
            message: $message,
            errorCode: $errorCode,
            httpStatusCode: 403,
            context: $context
        );
    }
}
