<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Validation;

use JsonSerializable;

final class ValidationResult implements JsonSerializable
{
    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    public function __construct(
        private array $errors = [],
        private array $warnings = []
    ) {
    }

    public static function success(): self
    {
        return new self([], []);
    }

    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    public static function failure(array $errors, array $warnings = []): self
    {
        return new self($errors, $warnings);
    }

    public function isValid(): bool
    {
        return empty($this->errors);
    }

    /**
     * @return list<string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @return list<string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function addError(string $error): void
    {
        $this->errors[] = $error;
    }

    public function addWarning(string $warning): void
    {
        $this->warnings[] = $warning;
    }

    public function merge(self $other): self
    {
        return new self(
            errors: array_merge($this->errors, $other->getErrors()),
            warnings: array_merge($this->warnings, $other->getWarnings())
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'is_valid' => $this->isValid(),
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
