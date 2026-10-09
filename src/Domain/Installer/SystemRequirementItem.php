<?php

declare(strict_types=1);

namespace Coleza\Domain\Installer;

use JsonSerializable;

/**
 * Value object representing a single system environment check result.
 */
final class SystemRequirementItem implements JsonSerializable
{
    public function __construct(
        private string $key,
        private string $category,
        private string $name,
        private string $required,
        private string $current,
        private bool $passed,
        private string $severity = 'REQUIRED',
        private string $message = ''
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getRequired(): string
    {
        return $this->required;
    }

    public function getCurrent(): string
    {
        return $this->current;
    }

    public function isPassed(): bool
    {
        return $this->passed;
    }

    public function isRequired(): bool
    {
        return strtoupper($this->severity) === 'REQUIRED';
    }

    public function getSeverity(): string
    {
        return $this->severity;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'category' => $this->category,
            'name' => $this->name,
            'required' => $this->required,
            'current' => $this->current,
            'passed' => $this->passed,
            'severity' => $this->severity,
            'message' => $this->message,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
