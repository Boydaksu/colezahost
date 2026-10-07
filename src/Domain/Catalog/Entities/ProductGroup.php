<?php

declare(strict_types=1);

namespace Coleza\Domain\Catalog\Entities;

final class ProductGroup
{
    /**
     * @param array<string, array{name: string, description?: string}> $translations Keyed by locale e.g. 'tr_TR', 'en_US'
     */
    public function __construct(
        private ?int $id,
        private string $slug,
        private string $name,
        private ?string $description = null,
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

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getName(?string $locale = null): string
    {
        if ($locale !== null && isset($this->translations[$locale]['name'])) {
            return $this->translations[$locale]['name'];
        }
        return $this->name;
    }

    public function getDescription(?string $locale = null): ?string
    {
        if ($locale !== null && isset($this->translations[$locale]['description'])) {
            return $this->translations[$locale]['description'];
        }
        return $this->description;
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
     * @return array<string, array{name: string, description?: string}>
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
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'sort_order' => $this->sortOrder,
            'is_active' => $this->isActive,
            'translations' => $this->translations,
            'created_at' => $this->createdAt,
        ];
    }
}
