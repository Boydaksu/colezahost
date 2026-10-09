<?php

declare(strict_types=1);

namespace Coleza\Domain\Support\Sla;

use Coleza\Domain\Support\Tickets\TicketPriority;
use DateTimeImmutable;

final class SlaPolicy
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly int $id,
        private readonly string $name,
        private readonly ?string $description = null,
        private readonly ?int $departmentId = null,
        private readonly int $criticalFirstResponseMinutes = 60,
        private readonly int $criticalResolutionMinutes = 240,
        private readonly int $highFirstResponseMinutes = 240,
        private readonly int $highResolutionMinutes = 720,
        private readonly int $mediumFirstResponseMinutes = 720,
        private readonly int $mediumResolutionMinutes = 1440,
        private readonly int $lowFirstResponseMinutes = 1440,
        private readonly int $lowResolutionMinutes = 2880,
        private readonly bool $isDefault = false,
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

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getDepartmentId(): ?int
    {
        return $this->departmentId;
    }

    public function getCriticalFirstResponseMinutes(): int
    {
        return $this->criticalFirstResponseMinutes;
    }

    public function getCriticalResolutionMinutes(): int
    {
        return $this->criticalResolutionMinutes;
    }

    public function getHighFirstResponseMinutes(): int
    {
        return $this->highFirstResponseMinutes;
    }

    public function getHighResolutionMinutes(): int
    {
        return $this->highResolutionMinutes;
    }

    public function getMediumFirstResponseMinutes(): int
    {
        return $this->mediumFirstResponseMinutes;
    }

    public function getMediumResolutionMinutes(): int
    {
        return $this->mediumResolutionMinutes;
    }

    public function getLowFirstResponseMinutes(): int
    {
        return $this->lowFirstResponseMinutes;
    }

    public function getLowResolutionMinutes(): int
    {
        return $this->lowResolutionMinutes;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
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
     * @return array{first_response_minutes: int, resolution_minutes: int}
     */
    public function getTargetsForPriority(string $priority): array
    {
        return match ($priority) {
            TicketPriority::CRITICAL => [
                'first_response_minutes' => $this->criticalFirstResponseMinutes,
                'resolution_minutes' => $this->criticalResolutionMinutes,
            ],
            TicketPriority::HIGH => [
                'first_response_minutes' => $this->highFirstResponseMinutes,
                'resolution_minutes' => $this->highResolutionMinutes,
            ],
            TicketPriority::LOW => [
                'first_response_minutes' => $this->lowFirstResponseMinutes,
                'resolution_minutes' => $this->lowResolutionMinutes,
            ],
            default => [
                'first_response_minutes' => $this->mediumFirstResponseMinutes,
                'resolution_minutes' => $this->mediumResolutionMinutes,
            ],
        };
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
            'department_id' => $this->departmentId,
            'critical_first_response_minutes' => $this->criticalFirstResponseMinutes,
            'critical_resolution_minutes' => $this->criticalResolutionMinutes,
            'high_first_response_minutes' => $this->highFirstResponseMinutes,
            'high_resolution_minutes' => $this->highResolutionMinutes,
            'medium_first_response_minutes' => $this->mediumFirstResponseMinutes,
            'medium_resolution_minutes' => $this->mediumResolutionMinutes,
            'low_first_response_minutes' => $this->lowFirstResponseMinutes,
            'low_resolution_minutes' => $this->lowResolutionMinutes,
            'is_default' => $this->isDefault,
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

        $createdAt = !empty($data['created_at']) && is_string($data['created_at'])
            ? new DateTimeImmutable($data['created_at'])
            : null;

        $updatedAt = !empty($data['updated_at']) && is_string($data['updated_at'])
            ? new DateTimeImmutable($data['updated_at'])
            : null;

        return new self(
            id: (int) $data['id'],
            name: (string) $data['name'],
            description: isset($data['description']) ? (string) $data['description'] : null,
            departmentId: isset($data['department_id']) && $data['department_id'] !== null ? (int) $data['department_id'] : null,
            criticalFirstResponseMinutes: (int) ($data['critical_first_response_minutes'] ?? 60),
            criticalResolutionMinutes: (int) ($data['critical_resolution_minutes'] ?? 240),
            highFirstResponseMinutes: (int) ($data['high_first_response_minutes'] ?? 240),
            highResolutionMinutes: (int) ($data['high_resolution_minutes'] ?? 720),
            mediumFirstResponseMinutes: (int) ($data['medium_first_response_minutes'] ?? 720),
            mediumResolutionMinutes: (int) ($data['medium_resolution_minutes'] ?? 1440),
            lowFirstResponseMinutes: (int) ($data['low_first_response_minutes'] ?? 1440),
            lowResolutionMinutes: (int) ($data['low_resolution_minutes'] ?? 2880),
            isDefault: (bool) ($data['is_default'] ?? false),
            isActive: (bool) ($data['is_active'] ?? true),
            metadata: $metadata,
            createdAt: $createdAt,
            updatedAt: $updatedAt
        );
    }
}
