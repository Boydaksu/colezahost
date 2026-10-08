<?php

declare(strict_types=1);

namespace Coleza\Domain\Providers\Settings;

use Coleza\Foundation\Exceptions\ValidationException;

final class ProviderSettingDefinition
{
    public const TYPE_STRING = 'string';
    public const TYPE_INT = 'int';
    public const TYPE_BOOL = 'bool';
    public const TYPE_SECRET = 'secret';
    public const TYPE_URL = 'url';
    public const TYPE_SELECT = 'select';

    /**
     * @param array<string, string> $options Available options if type is select
     */
    public function __construct(
        private string $key,
        private string $label,
        private string $type = self::TYPE_STRING,
        private bool $isSecret = false,
        private bool $isRequired = false,
        private mixed $defaultValue = null,
        private ?string $description = null,
        private array $options = []
    ) {
        if ($this->type === self::TYPE_SECRET) {
            $this->isSecret = true;
        }
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isSecret(): bool
    {
        return $this->isSecret;
    }

    public function isRequired(): bool
    {
        return $this->isRequired;
    }

    public function getDefaultValue(): mixed
    {
        return $this->defaultValue;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * @return array<string, string>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Validate a value against this setting definition.
     */
    public function validate(mixed $value): void
    {
        if ($this->isRequired && ($value === null || $value === '')) {
            throw new ValidationException(
                [$this->key => "Setting '{$this->label}' is required."],
                'Missing required setting'
            );
        }

        if ($value === null || $value === '') {
            return;
        }

        if ($this->type === self::TYPE_URL && is_string($value)) {
            if (!filter_var($value, FILTER_VALIDATE_URL)) {
                throw new ValidationException(
                    [$this->key => "Setting '{$this->label}' must be a valid URL."],
                    'Invalid URL setting'
                );
            }
        }

        if ($this->type === self::TYPE_SELECT && is_string($value)) {
            if (!empty($this->options) && !array_key_exists($value, $this->options)) {
                throw new ValidationException(
                    [$this->key => "Invalid option for '{$this->label}'."],
                    'Invalid select option'
                );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'is_secret' => $this->isSecret,
            'is_required' => $this->isRequired,
            'default_value' => $this->isSecret ? null : $this->defaultValue,
            'description' => $this->description,
            'options' => $this->options,
        ];
    }
}
