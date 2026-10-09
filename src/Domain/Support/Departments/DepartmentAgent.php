<?php

declare(strict_types=1);

namespace Coleza\Domain\Support\Departments;

use DateTimeImmutable;

final class DepartmentAgent
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly int $id,
        private readonly int $departmentId,
        private readonly int $userId,
        private readonly string $role = 'agent',
        private readonly bool $canAssign = true,
        private readonly bool $receivesNotifications = true,
        private readonly bool $isActive = true,
        private readonly array $metadata = [],
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getDepartmentId(): int
    {
        return $this->departmentId;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function canAssign(): bool
    {
        return $this->canAssign;
    }

    public function receivesNotifications(): bool
    {
        return $this->receivesNotifications;
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
            'department_id' => $this->departmentId,
            'user_id' => $this->userId,
            'role' => $this->role,
            'can_assign' => $this->canAssign,
            'receives_notifications' => $this->receivesNotifications,
            'is_active' => $this->isActive,
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
            departmentId: (int) $data['department_id'],
            userId: (int) $data['user_id'],
            role: (string) ($data['role'] ?? 'agent'),
            canAssign: (bool) ($data['can_assign'] ?? true),
            receivesNotifications: (bool) ($data['receives_notifications'] ?? true),
            isActive: (bool) ($data['is_active'] ?? true),
            metadata: $metadata,
            createdAt: $createdAt,
            updatedAt: $updatedAt
        );
    }
}
