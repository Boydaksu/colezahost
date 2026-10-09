<?php

declare(strict_types=1);

namespace Coleza\Domain\Privacy\Erasure;

final class ErasurePlanItem
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly string $category,
        private readonly string $entityType,
        private readonly int|string $identifier,
        private readonly ErasureAction $action,
        private readonly string $rationale,
        private readonly array $metadata = []
    ) {
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getIdentifier(): int|string
    {
        return $this->identifier;
    }

    public function getAction(): ErasureAction
    {
        return $this->action;
    }

    public function getRationale(): string
    {
        return $this->rationale;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'category' => $this->category,
            'entity_type' => $this->entityType,
            'identifier' => $this->identifier,
            'action' => $this->action->value,
            'rationale' => $this->rationale,
            'metadata' => $this->metadata,
        ];
    }
}
