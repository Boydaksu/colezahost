<?php

declare(strict_types=1);

namespace Coleza\Domain\Support\Announcements;

use DateTimeImmutable;

final class Announcement
{
    public const TYPE_GENERAL = 'general';
    public const TYPE_MAINTENANCE = 'maintenance';
    public const TYPE_INCIDENT = 'incident';
    public const TYPE_INFO = 'info';

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly int $id,
        private readonly string $title,
        private readonly string $slug,
        private readonly string $content,
        private readonly string $type = self::TYPE_GENERAL,
        private readonly bool $isPublic = true,
        private readonly bool $isActive = true,
        private readonly bool $isPinned = false,
        private readonly ?DateTimeImmutable $publishedAt = null,
        private readonly ?DateTimeImmutable $expiresAt = null,
        private readonly array $metadata = [],
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function isPinned(): bool
    {
        return $this->isPinned;
    }

    public function getPublishedAt(): ?DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
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

    public function isPublished(?DateTimeImmutable $now = null): bool
    {
        if ($this->publishedAt === null) {
            return false;
        }

        $currentTime = $now ?? new DateTimeImmutable();
        return $currentTime >= $this->publishedAt;
    }

    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        $currentTime = $now ?? new DateTimeImmutable();
        return $currentTime > $this->expiresAt;
    }

    public function isLive(?DateTimeImmutable $now = null): bool
    {
        return $this->isActive && $this->isPublished($now) && !$this->isExpired($now);
    }

    public function getTypeBadgeClass(): string
    {
        return match ($this->type) {
            self::TYPE_MAINTENANCE => 'badge-warning',
            self::TYPE_INCIDENT => 'badge-danger',
            self::TYPE_INFO => 'badge-info',
            default => 'badge-primary',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'content' => $this->content,
            'type' => $this->type,
            'is_public' => $this->isPublic,
            'is_active' => $this->isActive,
            'is_pinned' => $this->isPinned,
            'published_at' => $this->publishedAt?->format(DateTimeImmutable::ATOM),
            'expires_at' => $this->expiresAt?->format(DateTimeImmutable::ATOM),
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

        $publishedAt = !empty($data['published_at']) && is_string($data['published_at'])
            ? new DateTimeImmutable($data['published_at'])
            : null;

        $expiresAt = !empty($data['expires_at']) && is_string($data['expires_at'])
            ? new DateTimeImmutable($data['expires_at'])
            : null;

        $createdAt = !empty($data['created_at']) && is_string($data['created_at'])
            ? new DateTimeImmutable($data['created_at'])
            : null;

        $updatedAt = !empty($data['updated_at']) && is_string($data['updated_at'])
            ? new DateTimeImmutable($data['updated_at'])
            : null;

        return new self(
            id: (int) $data['id'],
            title: (string) $data['title'],
            slug: (string) $data['slug'],
            content: (string) $data['content'],
            type: (string) ($data['type'] ?? self::TYPE_GENERAL),
            isPublic: (bool) ($data['is_public'] ?? true),
            isActive: (bool) ($data['is_active'] ?? true),
            isPinned: (bool) ($data['is_pinned'] ?? false),
            publishedAt: $publishedAt,
            expiresAt: $expiresAt,
            metadata: $metadata,
            createdAt: $createdAt,
            updatedAt: $updatedAt
        );
    }
}
