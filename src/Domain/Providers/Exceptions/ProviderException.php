<?php

declare(strict_types=1);

namespace Coleza\Domain\Providers\Exceptions;

use RuntimeException;

class ProviderException extends RuntimeException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        #[\SensitiveParameter] string $message,
        private string $errorCode = 'PROVIDER_ERROR',
        #[\SensitiveParameter] private array $context = [],
        ?\Throwable $previous = null
    ) {
        $this->context = \Coleza\Foundation\Security\SecretRedactor::diagnostic($context);
        parent::__construct(\Coleza\Foundation\Security\SecretRedactor::message($message, $context), 0, $previous);
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
