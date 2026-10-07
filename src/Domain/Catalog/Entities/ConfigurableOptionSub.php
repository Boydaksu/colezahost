<?php

declare(strict_types=1);

namespace Coleza\Domain\Catalog\Entities;

final class ConfigurableOptionSub
{
    /**
     * @param array<string, array{name: string}> $translations
     */
    public function __construct(
        private ?int $id,
        private int $optionId,
        private string $name,
        private string $code,
        private int $sortOrder = 0,
        private bool $isActive = true,
        private array $translations = [],
        private ?string $createdAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOptionId(): int
    {
        return $this->optionId;
    }

    public function getName(?string $locale = null): string
    {
        if ($locale !== null && isset($this->translations[$locale]['name'])) {
            return $this->translations[$locale]['name'];
        }
        return $this->name;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    /**
     * @return array<string, array{name: string}>
     */
    public function getTranslations(): array
    {
        return $this->translations;
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
            'option_id' => $this->optionId,
            'name' => $this->name,
            'code' => $this->code,
            'sort_order' => $this->sortOrder,
            'is_active' => $this->isActive,
            'translations' => $this->translations,
            'created_at' => $this->createdAt,
        ];
    }
}
