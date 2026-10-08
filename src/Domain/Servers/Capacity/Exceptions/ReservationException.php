<?php

declare(strict_types=1);

namespace Coleza\Domain\Servers\Capacity\Exceptions;

use RuntimeException;

class ReservationException extends RuntimeException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message,
        private string $errorCode = 'RESERVATION_ERROR',
        private array $context = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
