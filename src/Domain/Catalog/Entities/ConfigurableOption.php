<?php

declare(strict_types=1);

namespace Coleza\Domain\Catalog\Entities;

final class ConfigurableOption
{
    public const TYPE_DROPDOWN = 'dropdown';
    public const TYPE_RADIO = 'radio';
    public const TYPE_CHECKBOX = 'checkbox';
    public const TYPE_QUANTITY = 'quantity';

    /**
     * @param array<ConfigurableOptionSub> $subOptions
     * @param array<string, array{name: string, description?: string}> $translations
     */
    public function __construct(
        private ?int $id,
        private ?int $productId,
        private ?int $groupId,
        private string $name,
        private string $code,
        private string $type = self::TYPE_DROPDOWN,
        private int $sortOrder = 0,
        private bool $isRequired = false,
        private bool $isActive = true,
        private array $subOptions = [],
        private array $translations = [],
        private ?string $createdAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProductId(): ?int
    {
        return $this->productId;
    }

    public function getGroupId(): ?int
    {
        return $this->groupId;
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

    public function getType(): string
    {
        return $this->type;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function isRequired(): bool
    {
        return $this->isRequired;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    /**
     * @return array<ConfigurableOptionSub>
     */
    public function getSubOptions(): array
    {
        return $this->subOptions;
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
            'product_id' => $this->productId,
            'group_id' => $this->groupId,
            'name' => $this->name,
            'code' => $this->code,
            'type' => $this->type,
            'sort_order' => $this->sortOrder,
            'is_required' => $this->isRequired,
            'is_active' => $this->isActive,
            'sub_options' => array_map(fn($s) => $s->toArray(), $this->subOptions),
            'translations' => $this->translations,
            'created_at' => $this->createdAt,
        ];
    }
}
