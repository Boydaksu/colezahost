<?php

declare(strict_types=1);

namespace Coleza\Domain\Support\Announcements;

use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use DateTimeImmutable;

final class AnnouncementService
{
    private string $table = 'support_announcements';

    public function __construct(
        private readonly Connection $db
    ) {
    }

    public function ensureTables(): void
    {
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                title VARCHAR(200) NOT NULL,
                slug VARCHAR(200) NOT NULL UNIQUE,
                content TEXT NOT NULL,
                type VARCHAR(32) NOT NULL DEFAULT \'general\',
                is_public TINYINT(1) NOT NULL DEFAULT 1,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                is_pinned TINYINT(1) NOT NULL DEFAULT 0,
                published_at TIMESTAMP NULL,
                expires_at TIMESTAMP NULL,
                metadata_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->table,
            $autoInc
        );
        $this->db->statement($sql);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): Announcement
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new ValidationException(
                ['title' => 'Announcement title is required.'],
                'Invalid title'
            );
        }

        $content = trim((string) ($data['content'] ?? ''));
        if ($content === '') {
            throw new ValidationException(
                ['content' => 'Announcement content is required.'],
                'Invalid content'
            );
        }

        $slug = trim((string) ($data['slug'] ?? ''));
        if ($slug === '') {
            $slug = $this->slugify($title);
        } else {
            $slug = $this->slugify($slug);
        }

        $existing = $this->getBySlug($slug);
        if ($existing !== null) {
            $slug = $slug . '-' . bin2hex(random_bytes(2));
        }

        $type = (string) ($data['type'] ?? Announcement::TYPE_GENERAL);
        $isPublic = (bool) ($data['is_public'] ?? true);
        $isActive = (bool) ($data['is_active'] ?? true);
        $isPinned = (bool) ($data['is_pinned'] ?? false);

        $now = new DateTimeImmutable();
        $publishedAt = !empty($data['published_at'])
            ? ($data['published_at'] instanceof DateTimeImmutable ? $data['published_at'] : new DateTimeImmutable((string) $data['published_at']))
            : $now;

        $expiresAt = !empty($data['expires_at'])
            ? ($data['expires_at'] instanceof DateTimeImmutable ? $data['expires_at'] : new DateTimeImmutable((string) $data['expires_at']))
            : null;

        $metadata = isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : [];
        $nowStr = $now->format('Y-m-d H:i:s');

        $insertedId = $this->db->insert(
            $this->table,
            [
                'title' => $title,
                'slug' => $slug,
                'content' => $content,
                'type' => $type,
                'is_public' => $isPublic ? 1 : 0,
                'is_active' => $isActive ? 1 : 0,
                'is_pinned' => $isPinned ? 1 : 0,
                'published_at' => $publishedAt?->format('Y-m-d H:i:s'),
                'expires_at' => $expiresAt?->format('Y-m-d H:i:s'),
                'metadata_json' => json_encode($metadata),
                'created_at' => $nowStr,
                'updated_at' => $nowStr,
            ]
        );

        $id = (int) $insertedId;

        return new Announcement(
            id: $id,
            title: $title,
            slug: $slug,
            content: $content,
            type: $type,
            isPublic: $isPublic,
            isActive: $isActive,
            isPinned: $isPinned,
            publishedAt: $publishedAt,
            expiresAt: $expiresAt,
            metadata: $metadata,
            createdAt: $now,
            updatedAt: $now
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): Announcement
    {
        $current = $this->require($id);

        $updates = [];
        $title = $current->getTitle();
        $content = $current->getContent();
        $slug = $current->getSlug();
        $type = $current->getType();
        $isPublic = $current->isPublic();
        $isActive = $current->isActive();
        $isPinned = $current->isPinned();
        $publishedAt = $current->getPublishedAt();
        $expiresAt = $current->getExpiresAt();
        $metadata = $current->getMetadata();

        if (array_key_exists('title', $data)) {
            $newTitle = trim((string) $data['title']);
            if ($newTitle === '') {
                throw new ValidationException(
                    ['title' => 'Announcement title cannot be empty.'],
                    'Invalid title'
                );
            }
            $title = $newTitle;
            $updates['title'] = $title;
        }

        if (array_key_exists('content', $data)) {
            $newContent = trim((string) $data['content']);
            if ($newContent === '') {
                throw new ValidationException(
                    ['content' => 'Announcement content cannot be empty.'],
                    'Invalid content'
                );
            }
            $content = $newContent;
            $updates['content'] = $content;
        }

        if (array_key_exists('slug', $data)) {
            $newSlug = $this->slugify((string) $data['slug']);
            if ($newSlug !== $current->getSlug()) {
                $existing = $this->getBySlug($newSlug);
                if ($existing !== null && $existing->getId() !== $id) {
                    throw new ValidationException(
                        ['slug' => "Slug '{$newSlug}' already in use."],
                        'Duplicate slug'
                    );
                }
            }
            $slug = $newSlug;
            $updates['slug'] = $slug;
        }

        if (array_key_exists('type', $data)) {
            $type = (string) $data['type'];
            $updates['type'] = $type;
        }

        if (array_key_exists('is_public', $data)) {
            $isPublic = (bool) $data['is_public'];
            $updates['is_public'] = $isPublic ? 1 : 0;
        }

        if (array_key_exists('is_active', $data)) {
            $isActive = (bool) $data['is_active'];
            $updates['is_active'] = $isActive ? 1 : 0;
        }

        if (array_key_exists('is_pinned', $data)) {
            $isPinned = (bool) $data['is_pinned'];
            $updates['is_pinned'] = $isPinned ? 1 : 0;
        }

        if (array_key_exists('published_at', $data)) {
            $publishedAt = !empty($data['published_at'])
                ? ($data['published_at'] instanceof DateTimeImmutable ? $data['published_at'] : new DateTimeImmutable((string) $data['published_at']))
                : null;
            $updates['published_at'] = $publishedAt?->format('Y-m-d H:i:s');
        }

        if (array_key_exists('expires_at', $data)) {
            $expiresAt = !empty($data['expires_at'])
                ? ($data['expires_at'] instanceof DateTimeImmutable ? $data['expires_at'] : new DateTimeImmutable((string) $data['expires_at']))
                : null;
            $updates['expires_at'] = $expiresAt?->format('Y-m-d H:i:s');
        }

        if (array_key_exists('metadata', $data) && is_array($data['metadata'])) {
            $metadata = array_merge($metadata, $data['metadata']);
            $updates['metadata_json'] = json_encode($metadata);
        }

        $now = new DateTimeImmutable();
        $updates['updated_at'] = $now->format('Y-m-d H:i:s');

        $this->db->update($this->table, $updates, 'id = :where_id', ['where_id' => $id]);

        return new Announcement(
            id: $id,
            title: $title,
            slug: $slug,
            content: $content,
            type: $type,
            isPublic: $isPublic,
            isActive: $isActive,
            isPinned: $isPinned,
            publishedAt: $publishedAt,
            expiresAt: $expiresAt,
            metadata: $metadata,
            createdAt: $current->getCreatedAt(),
            updatedAt: $now
        );
    }

    public function delete(int $id): bool
    {
        $this->require($id);
        $affected = $this->db->delete($this->table, 'id = :where_id', ['where_id' => $id]);

        return $affected > 0;
    }

    public function get(int $id): ?Announcement
    {
        $row = $this->db->selectOne(
            sprintf('SELECT * FROM %s WHERE id = :id', $this->table),
            ['id' => $id]
        );

        return $row !== null ? Announcement::fromArray($row) : null;
    }

    public function require(int $id): Announcement
    {
        $ann = $this->get($id);
        if ($ann === null) {
            throw new ValidationException(
                ['announcement_id' => "Announcement with ID {$id} not found."],
                'Announcement not found'
            );
        }

        return $ann;
    }

    public function getBySlug(string $slug): ?Announcement
    {
        $row = $this->db->selectOne(
            sprintf('SELECT * FROM %s WHERE slug = :slug', $this->table),
            ['slug' => trim($slug)]
        );

        return $row !== null ? Announcement::fromArray($row) : null;
    }

    /**
     * Retrieve current live announcements (active, published, not expired).
     *
     * @return array<int, Announcement>
     */
    public function listLiveAnnouncements(bool $onlyPublic = true, ?DateTimeImmutable $now = null): array
    {
        $currentTime = $now ?? new DateTimeImmutable();
        $nowStr = $currentTime->format('Y-m-d H:i:s');

        $conditions = [
            'is_active = 1',
            '(published_at IS NULL OR published_at <= :now_pub)',
            '(expires_at IS NULL OR expires_at > :now_exp)',
        ];
        $bindings = [
            'now_pub' => $nowStr,
            'now_exp' => $nowStr,
        ];

        if ($onlyPublic) {
            $conditions[] = 'is_public = 1';
        }

        $sql = sprintf(
            'SELECT * FROM %s WHERE %s ORDER BY is_pinned DESC, published_at DESC, id DESC',
            $this->table,
            implode(' AND ', $conditions)
        );

        $rows = $this->db->select($sql, $bindings);

        return array_map(fn (array $r) => Announcement::fromArray($r), $rows);
    }

    private function slugify(string $text): string
    {
        $slug = strtolower(trim($text));
        $slug = preg_replace('/[^\w\-]+/u', '-', $slug);
        $slug = trim((string) $slug, '-');

        return $slug ?: 'announcement-' . bin2hex(random_bytes(3));
    }
}
