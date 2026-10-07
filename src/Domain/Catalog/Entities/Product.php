<?php

declare(strict_types=1);

namespace Coleza\Domain\Catalog\Entities;

final class Product
{
    public const TYPE_HOSTING = 'hosting';
    public const TYPE_DOMAIN = 'domain';
    public const TYPE_SSL = 'ssl';
    public const TYPE_SERVER = 'server';
    public const TYPE_OTHER = 'other';

    /**
     * @param array<string, array{name: string, description?: string, tag_line?: string, features?: array<string>}> $translations
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private ?int $id,
        private int $groupId,
        private string $slug,
        private string $type,
        private string $name,
        private ?string $description = null,
        private ?string $tagLine = null,
        private array $features = [],
        private int $sortOrder = 0,
        private bool $isActive = true,
        private bool $isFeatured = false,
        private array $metadata = [],
        private array $translations = [],
        private ?string $createdAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGroupId(): int
    {
        return $this->groupId;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getType(): string
    {
        return $this->type;
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

    public function getTagLine(?string $locale = null): ?string
    {
        if ($locale !== null && isset($this->translations[$locale]['tag_line'])) {
            return $this->translations[$locale]['tag_line'];
        }
        return $this->tagLine;
    }

    /**
     * @return array<string>
     */
    public function getFeatures(?string $locale = null): array
    {
        if ($locale !== null && isset($this->translations[$locale]['features']) && is_array($this->translations[$locale]['features'])) {
            return $this->translations[$locale]['features'];
        }
        return $this->features;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function isFeatured(): bool
    {
        return $this->isFeatured;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * @return array<string, array{name: string, description?: string, tag_line?: string, features?: array<string>}>
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
            'group_id' => $this->groupId,
            'slug' => $this->slug,
            'type' => $this->type,
            'name' => $this->name,
            'description' => $this->description,
            'tag_line' => $this->tagLine,
            'features' => $this->features,
            'sort_order' => $this->sortOrder,
            'is_active' => $this->isActive,
            'is_featured' => $this->isFeatured,
            'metadata' => $this->metadata,
            'translations' => $this->translations,
            'created_at' => $this->createdAt,
        ];
    }
}
