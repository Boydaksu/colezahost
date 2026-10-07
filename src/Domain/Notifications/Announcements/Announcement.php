<?php

declare(strict_types=1);

namespace Coleza\Domain\Notifications\Announcements;

final class Announcement
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private ?int $id,
        private string $title,
        private string $slug,
        private string $content,
        private string $category = 'general', // 'maintenance', 'general', 'incident', 'promotion'
        private bool $isPublished = false,
        private ?string $publishedAt = null,
        private ?string $expiresAt = null,
        private bool $isPinned = false,
        private ?int $authorAdminId = null,
        private ?string $createdAt = null,
        private ?string $updatedAt = null,
        private array $metadata = []
    ) {
    }

    public function getId(): ?int
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

    public function getCategory(): string
    {
        return $this->category;
    }

    public function isPublished(): bool
    {
        return $this->isPublished;
    }

    public function getPublishedAt(): ?string
    {
        return $this->publishedAt;
    }

    public function getExpiresAt(): ?string
    {
        return $this->expiresAt;
    }

    public function isPinned(): bool
    {
        return $this->isPinned;
    }

    public function getAuthorAdminId(): ?int
    {
        return $this->authorAdminId;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function isActive(?string $referenceDate = null): bool
    {
        if (!$this->isPublished) {
            return false;
        }

        if ($this->expiresAt === null) {
            return true;
        }

        $ref = $referenceDate !== null ? strtotime($referenceDate) : time();
        $expireTime = strtotime($this->expiresAt);

        return $expireTime === false || $ref <= $expireTime;
    }
}
