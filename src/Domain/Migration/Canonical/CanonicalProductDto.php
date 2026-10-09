<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Canonical;

final class CanonicalProductDto implements CanonicalEntityInterface
{
    /**
     * @param array<string, mixed> $moduleConfig
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private string $sourceId,
        private string $sourceSystem,
        private string $name,
        private string $type, // 'hosting', 'reseller', 'vps', 'server', 'other'
        private string $description = '',
        private float $price = 0.0,
        private string $currency = 'USD',
        private string $billingCycle = 'monthly',
        private ?string $module = null,
        private ?string $packageName = null,
        private bool $isActive = true,
        private array $moduleConfig = [],
        private array $metadata = []
    ) {
    }

    public function getSourceId(): string
    {
        return $this->sourceId;
    }

    public function getSourceSystem(): string
    {
        return $this->sourceSystem;
    }

    public function getEntityType(): string
    {
        return 'product';
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getBillingCycle(): string
    {
        return $this->billingCycle;
    }

    public function getModule(): ?string
    {
        return $this->module;
    }

    public function getPackageName(): ?string
    {
        return $this->packageName;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    /**
     * @return array<string, mixed>
     */
    public function getModuleConfig(): array
    {
        return $this->moduleConfig;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function toArray(): array
    {
        return [
            'source_id' => $this->sourceId,
            'source_system' => $this->sourceSystem,
            'entity_type' => $this->getEntityType(),
            'name' => $this->name,
            'type' => $this->type,
            'description' => $this->description,
            'price' => $this->price,
            'currency' => $this->currency,
            'billing_cycle' => $this->billingCycle,
            'module' => $this->module,
            'package_name' => $this->packageName,
            'is_active' => $this->isActive,
            'module_config' => $this->moduleConfig,
            'metadata' => $this->metadata,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
