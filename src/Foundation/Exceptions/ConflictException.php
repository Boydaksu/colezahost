<?php

declare(strict_types=1);

namespace Coleza\Foundation\Exceptions;

final class ConflictException extends AppException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message = 'The operation conflicted with existing state.',
        string $errorCode = 'STATE_CONFLICT',
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
