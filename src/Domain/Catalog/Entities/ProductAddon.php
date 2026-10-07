<?php

declare(strict_types=1);

namespace Coleza\Domain\Catalog\Entities;

final class ProductAddon
{
    /**
     * @param array<int> $applicableProductIds Empty means all products or globally applicable
     * @param array<string, array{name: string, description?: string}> $translations
     */
    public function __construct(
        private ?int $id,
        private string $name,
        private string $code,
        private ?string $description = null,
        private array $applicableProductIds = [],
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

    public function getDescription(?string $locale = null): ?string
    {
        if ($locale !== null && isset($this->translations[$locale]['description'])) {
            return $this->translations[$locale]['description'];
        }
        return $this->description;
    }

    /**
     * @return array<int>
     */
    public function getApplicableProductIds(): array
    {
        return $this->applicableProductIds;
    }

    public function isApplicableTo(int $productId): bool
    {
        if (empty($this->applicableProductIds)) {
            return true;
        }
        return in_array($productId, $this->applicableProductIds, true);
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
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'applicable_product_ids' => $this->applicableProductIds,
            'sort_order' => $this->sortOrder,
            'is_active' => $this->isActive,
            'translations' => $this->translations,
            'created_at' => $this->createdAt,
        ];
    }
}
