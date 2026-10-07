<?php

declare(strict_types=1);

namespace Coleza\Domain\Tax\Entities;

final class TaxClass
{
    public const DEFAULT_STANDARD = 'standard';
    public const DEFAULT_REDUCED = 'reduced';
    public const DEFAULT_ZERO = 'zero';

    public function __construct(
        private ?int $id,
        private string $code, // e.g., 'standard', 'digital_goods', 'hosting'
        private string $name,
        private ?string $description = null,
        private bool $isDefault = false,
        private ?string $createdAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return strtolower($this->code);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->getCode(),
            'name' => $this->name,
            'description' => $this->description,
            'is_default' => $this->isDefault,
            'created_at' => $this->createdAt,
        ];
    }
}
