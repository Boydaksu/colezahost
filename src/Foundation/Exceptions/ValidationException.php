<?php

declare(strict_types=1);

namespace Coleza\Foundation\Exceptions;

final class ValidationException extends AppException
{
    /**
     * @param array<string, array<int, string>> $errors
     */
    public function __construct(
        private array $errors,
        string $message = 'The given data failed validation.'
    ) {
        parent::__construct(
            message: $message,
            errorCode: 'VALIDATION_FAILED',
            httpStatusCode: 422,
            context: ['errors' => $errors]
        );
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function toResponseArray(bool $includeDetails = true): array
    {
        return [
            'error' => $this->getMessage(),
            'code' => $this->errorCode,
            'errors' => $this->errors,
        ];
    }
}
