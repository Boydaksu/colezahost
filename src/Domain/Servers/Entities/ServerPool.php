<?php

declare(strict_types=1);

namespace Coleza\Domain\Servers\Entities;

final class ServerPool
{
    public const STRATEGY_LEAST_LOADED = 'least_loaded';
    public const STRATEGY_ROUND_ROBIN = 'round_robin';
    public const STRATEGY_FILL_FIRST = 'fill_first';
    public const STRATEGY_RANDOM = 'random';

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private ?int $id,
        private string $name,
        private string $slug,
        private string $providerSlug,
        private ?int $locationId = null,
        private string $strategy = self::STRATEGY_LEAST_LOADED,
        private bool $isActive = true,
        private array $metadata = [],
        private ?string $createdAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getProviderSlug(): string
    {
        return $this->providerSlug;
    }

    public function getLocationId(): ?int
    {
        return $this->locationId;
    }

    public function getStrategy(): string
    {
        return $this->strategy;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    /**
     * @return array<string>
     */
    public static function supportedStrategies(): array
    {
        return [
            self::STRATEGY_LEAST_LOADED,
            self::STRATEGY_ROUND_ROBIN,
            self::STRATEGY_FILL_FIRST,
            self::STRATEGY_RANDOM,
        ];
    }

    public static function isValidStrategy(string $strategy): bool
    {
        return in_array($strategy, self::supportedStrategies(), true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'provider_slug' => $this->providerSlug,
            'location_id' => $this->locationId,
            'strategy' => $this->strategy,
            'is_active' => $this->isActive,
            'metadata' => $this->metadata,
            'created_at' => $this->createdAt,
        ];
    }
}
