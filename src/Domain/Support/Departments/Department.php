<?php

declare(strict_types=1);

namespace Coleza\Domain\Support\Departments;

use DateTimeImmutable;

final class Department
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly int $id,
        private readonly string $name,
        private readonly ?string $description = null,
        private readonly ?string $email = null,
        private readonly bool $isPublic = true,
        private readonly bool $isActive = true,
        private readonly int $sortOrder = 0,
        private readonly array $metadata = [],
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'email' => $this->email,
            'is_public' => $this->isPublic,
            'is_active' => $this->isActive,
            'sort_order' => $this->sortOrder,
            'metadata' => $this->metadata,
            'created_at' => $this->createdAt?->format(DateTimeImmutable::ATOM),
            'updated_at' => $this->updatedAt?->format(DateTimeImmutable::ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $metadata = [];
        if (isset($data['metadata_json']) && is_string($data['metadata_json'])) {
            $decoded = json_decode($data['metadata_json'], true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        } elseif (isset($data['metadata']) && is_array($data['metadata'])) {
            $metadata = $data['metadata'];
        }

        $createdAt = null;
        if (!empty($data['created_at']) && is_string($data['created_at'])) {
            $createdAt = new DateTimeImmutable($data['created_at']);
        }

        $updatedAt = null;
        if (!empty($data['updated_at']) && is_string($data['updated_at'])) {
            $updatedAt = new DateTimeImmutable($data['updated_at']);
        }

        return new self(
            id: (int) $data['id'],
            name: (string) $data['name'],
            description: isset($data['description']) ? (string) $data['description'] : null,
            email: isset($data['email']) ? (string) $data['email'] : null,
            isPublic: (bool) ($data['is_public'] ?? true),
            isActive: (bool) ($data['is_active'] ?? true),
            sortOrder: (int) ($data['sort_order'] ?? 0),
            metadata: $metadata,
            createdAt: $createdAt,
            updatedAt: $updatedAt
        );
    }
}
