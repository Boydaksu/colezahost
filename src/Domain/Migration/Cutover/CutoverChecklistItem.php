<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Cutover;

use JsonSerializable;

/**
 * Represents a single verification check on the cutover checklist.
 */
final class CutoverChecklistItem implements JsonSerializable
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        private string $key,
        private string $title,
        private bool $passed,
        private string $message,
        private bool $required = true,
        private array $details = [],
        private ?string $checkedAt = null
    ) {
        $this->checkedAt ??= date('Y-m-d H:i:s');
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function isPassed(): bool
    {
        return $this->passed;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    public function getCheckedAt(): string
    {
        return $this->checkedAt ?? date('Y-m-d H:i:s');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'passed' => $this->passed,
            'required' => $this->required,
            'message' => $this->message,
            'details' => $this->details,
            'checked_at' => $this->checkedAt,
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
