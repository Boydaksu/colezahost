<?php

declare(strict_types=1);

namespace Coleza\Foundation\Exceptions;

final class ResourceNotFoundException extends AppException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message = 'The requested resource was not found.',
        string $errorCode = 'RESOURCE_NOT_FOUND',
        array $context = []
    ) {
        parent::__construct(
            message: $message,
            errorCode: $errorCode,
            httpStatusCode: 404,
            context: $context
        );
    }
}
