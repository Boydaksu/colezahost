<?php

declare(strict_types=1);

namespace Coleza\Domain\Notifications\Announcements;

use Coleza\Domain\Notifications\Center\NotificationCenterService;
use Coleza\Domain\Notifications\Channel\NotificationChannel;
use Coleza\Domain\Notifications\NotificationEngine;
use Coleza\Domain\Notifications\Unsubscribe\UnsubscribeService;
use Coleza\Foundation\Exceptions\ValidationException;
use Coleza\Foundation\Database\PdoSchema;
use PDO;
use RuntimeException;

final class AnnouncementService
{
    private NotificationCenterService $centerService;
    private UnsubscribeService $unsubscribeService;

    /**
     * @var array<int, Announcement> In-memory cache when PDO is null
     */
    private array $memoryAnnouncements = [];

    public function __construct(
        private ?PDO $pdo = null,
        ?NotificationCenterService $centerService = null,
        ?UnsubscribeService $unsubscribeService = null
    ) {
        if ($this->pdo !== null) { PdoSchema::autoIncrement($this->pdo); }
        $this->centerService = $centerService ?? new NotificationCenterService($this->pdo);
        $this->unsubscribeService = $unsubscribeService ?? new UnsubscribeService();

        if ($this->pdo !== null) {
            $this->ensureSchema();
        }
    }

    public function createAnnouncement(
        string $title,
        string $content,
        string $category = 'general',
        bool $isPinned = false,
        ?string $expiresAt = null,
        ?int $authorAdminId = null
    ): Announcement {
        if (trim($title) === '') {
            throw new ValidationException(['title' => ['Title cannot be empty.']], 'Invalid title');
        }

        if (trim($content) === '') {
            throw new ValidationException(['content' => ['Content cannot be empty.']], 'Invalid content');
        }

        $slug = $this->generateSlug($title);
        $now = date('c');

        $announcement = new Announcement(
            id: null,
            title: trim($title),
            slug: $slug,
            content: trim($content),
            category: $category,
            isPublished: false,
            publishedAt: null,
            expiresAt: $expiresAt,
            isPinned: $isPinned,
            authorAdminId: $authorAdminId,
            createdAt: $now,
            updatedAt: $now
        );

        return $this->persistAnnouncement($announcement);
    }

    public function publishAnnouncement(int $id): Announcement
    {
        $announcement = $this->find($id);
        if ($announcement === null) {
            throw new RuntimeException("Announcement #{$id} not found.");
        }

        $now = date('c');
        $updated = new Announcement(
            id: $announcement->getId(),
            title: $announcement->getTitle(),
            slug: $announcement->getSlug(),
            content: $announcement->getContent(),
            category: $announcement->getCategory(),
            isPublished: true,
            publishedAt: $now,
            expiresAt: $announcement->getExpiresAt(),
            isPinned: $announcement->isPinned(),
            authorAdminId: $announcement->getAuthorAdminId(),
            createdAt: $announcement->getCreatedAt(),
            updatedAt: $now,
            metadata: $announcement->getMetadata()
        );

        return $this->updateAnnouncement($updated);
    }

    /**
     * Broadcasts a marketing announcement via email respecting customer opt-out preferences and appending RFC 8058 unsubscribe headers.
     *
     * @param int $announcementId
     * @param NotificationEngine $engine
     * @param array<array{userId: int, email: string, name?: string, locale?: string}> $recipients
     * @param string $baseUrl
     * @return array{sent: int, skipped: int}
     */
    public function broadcastMarketingAnnouncement(
        int $announcementId,
        NotificationEngine $engine,
        array $recipients,
        string $baseUrl = 'https://panel.coleza.com'
    ): array {
        $announcement = $this->find($announcementId);
        if ($announcement === null) {
            throw new RuntimeException("Announcement #{$announcementId} not found.");
        }

        if (!$announcement->isPublished()) {
            throw new RuntimeException("Cannot broadcast unpublished announcement #{$announcementId}.");
        }

        $sentCount = 0;
        $skippedCount = 0;

        foreach ($recipients as $recipient) {
            $userId = (int) ($recipient['userId'] ?? 0);
            $email = (string) ($recipient['email'] ?? '');
            $name = $recipient['name'] ?? null;
            $locale = $recipient['locale'] ?? 'en';

            if ($email === '') {
                $skippedCount++;
                continue;
            }

            // Check if user has opted out of marketing
            if ($userId > 0 && !$this->centerService->isChannelEnabled($userId, 'marketing', NotificationChannel::EMAIL)) {
                $skippedCount++;
                continue;
            }

            // Generate tokenized one-click unsubscribe URL
            $unsubToken = $this->unsubscribeService->generateToken($userId, $email, 'marketing');
            $unsubUrl = rtrim($baseUrl, '/') . "/unsubscribe?token=" . urlencode($unsubToken);

            $unsubscribeNotice = $locale === 'tr'
                ? "<p style='font-size: 11px; color: #94a3b8; margin-top: 24px;'>Bu e-postayı almak istemiyorsanız <a href='{$unsubUrl}'>buraya tıklayarak abonelikten çıkabilirsiniz</a>.</p>"
                : "<p style='font-size: 11px; color: #94a3b8; margin-top: 24px;'>To unsubscribe from marketing updates, <a href='{$unsubUrl}'>click here</a>.</p>";

            $htmlBody = "<div style='font-size: 15px;'>{$announcement->getContent()}</div>" . $unsubscribeNotice;
            $plainText = strip_tags($announcement->getContent()) . "\n\nUnsubscribe: {$unsubUrl}";

            $result = $engine->sendDirectEmail(
                recipientEmail: $email,
                subject: $announcement->getTitle(),
                htmlBody: $htmlBody,
                plainText: $plainText,
                recipientName: $name,
                recipientUserId: $userId > 0 ? $userId : null,
                locale: $locale,
                options: [
                    'tags' => ['marketing', 'announcement_' . $announcement->getId()],
                    'metadata' => [
                        'announcement_id' => $announcement->getId(),
                        'list_unsubscribe_headers' => $this->unsubscribeService->buildListUnsubscribeHeaders($unsubUrl),
                    ],
                ]
            );

            if ($result->isSuccess()) {
                $sentCount++;
                if ($userId > 0) {
                    $this->centerService->recordDelivery(
                        channel: NotificationChannel::EMAIL,
                        recipient: $email,
                        subject: $announcement->getTitle(),
                        status: 'sent',
                        userId: $userId,
                        templateKey: 'marketing_announcement',
                        messageId: $result->getMessageId()
                    );
                }
            } else {
                $skippedCount++;
            }
        }

        return [
            'sent' => $sentCount,
            'skipped' => $skippedCount,
        ];
    }

    /**
     * @return array<Announcement>
     */
    public function getPublicAnnouncements(int $limit = 20, int $offset = 0): array
    {
        if ($this->pdo === null) {
            $filtered = array_filter($this->memoryAnnouncements, fn(Announcement $a) => $a->isActive());
            $sorted = array_values($filtered);
            usort($sorted, fn(Announcement $a, Announcement $b) => ($b->isPinned() ? 1 : 0) <=> ($a->isPinned() ? 1 : 0));
            return array_slice($sorted, $offset, $limit);
        }

        $now = date('c');
        $stmt = $this->pdo->prepare(
            'SELECT * FROM announcements 
             WHERE is_published = 1 AND (expires_at IS NULL OR expires_at > :now)
             ORDER BY is_pinned DESC, id DESC 
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':now', $now);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = $this->mapRow($row);
        }

        return $results;
    }

    /**
     * @return array<Announcement>
     */
    public function getActiveAlerts(): array
    {
        if ($this->pdo === null) {
            return array_values(array_filter($this->memoryAnnouncements, fn(Announcement $a) => $a->isActive() && $a->isPinned()));
        }

        $now = date('c');
        $stmt = $this->pdo->prepare(
            'SELECT * FROM announcements 
             WHERE is_published = 1 AND is_pinned = 1 AND (expires_at IS NULL OR expires_at > :now)
             ORDER BY id DESC'
        );
        $stmt->execute([':now' => $now]);

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = $this->mapRow($row);
        }

        return $results;
    }

    public function find(int $id): ?Announcement
    {
        if ($this->pdo === null) {
            return $this->memoryAnnouncements[$id] ?? null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM announcements WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapRow($row) : null;
    }

    private function persistAnnouncement(Announcement $announcement): Announcement
    {
        if ($this->pdo === null) {
            $id = count($this->memoryAnnouncements) + 1;
            $saved = new Announcement(
                id: $id,
                title: $announcement->getTitle(),
                slug: $announcement->getSlug(),
                content: $announcement->getContent(),
                category: $announcement->getCategory(),
                isPublished: $announcement->isPublished(),
                publishedAt: $announcement->getPublishedAt(),
                expiresAt: $announcement->getExpiresAt(),
                isPinned: $announcement->isPinned(),
                authorAdminId: $announcement->getAuthorAdminId(),
                createdAt: $announcement->getCreatedAt(),
                updatedAt: $announcement->getUpdatedAt(),
                metadata: $announcement->getMetadata()
            );
            $this->memoryAnnouncements[$id] = $saved;
            return $saved;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO announcements (
                title, slug, content, category, is_published, published_at, expires_at,
                is_pinned, author_admin_id, created_at, updated_at
            ) VALUES (
                :title, :slug, :content, :category, :is_published, :published_at, :expires_at,
                :is_pinned, :author_admin_id, :created_at, :updated_at
            )'
        );

        $stmt->execute([
            ':title' => $announcement->getTitle(),
            ':slug' => $announcement->getSlug(),
            ':content' => $announcement->getContent(),
            ':category' => $announcement->getCategory(),
            ':is_published' => $announcement->isPublished() ? 1 : 0,
            ':published_at' => $announcement->getPublishedAt(),
            ':expires_at' => $announcement->getExpiresAt(),
            ':is_pinned' => $announcement->isPinned() ? 1 : 0,
            ':author_admin_id' => $announcement->getAuthorAdminId(),
            ':created_at' => $announcement->getCreatedAt(),
            ':updated_at' => $announcement->getUpdatedAt(),
        ]);

        $id = (int) $this->pdo->lastInsertId();

        return new Announcement(
            id: $id,
            title: $announcement->getTitle(),
            slug: $announcement->getSlug(),
            content: $announcement->getContent(),
            category: $announcement->getCategory(),
            isPublished: $announcement->isPublished(),
            publishedAt: $announcement->getPublishedAt(),
            expiresAt: $announcement->getExpiresAt(),
            isPinned: $announcement->isPinned(),
            authorAdminId: $announcement->getAuthorAdminId(),
            createdAt: $announcement->getCreatedAt(),
            updatedAt: $announcement->getUpdatedAt(),
            metadata: $announcement->getMetadata()
        );
    }

    private function updateAnnouncement(Announcement $announcement): Announcement
    {
        if ($announcement->getId() === null) {
            throw new RuntimeException('Cannot update announcement without ID.');
        }

        if ($this->pdo === null) {
            $this->memoryAnnouncements[$announcement->getId()] = $announcement;
            return $announcement;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE announcements SET
                is_published = :is_published,
                published_at = :published_at,
                updated_at = :updated_at
            WHERE id = :id'
        );

        $stmt->execute([
            ':is_published' => $announcement->isPublished() ? 1 : 0,
            ':published_at' => $announcement->getPublishedAt(),
            ':updated_at' => $announcement->getUpdatedAt(),
            ':id' => $announcement->getId(),
        ]);

        return $announcement;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): Announcement
    {
        return new Announcement(
            id: (int) $row['id'],
            title: (string) $row['title'],
            slug: (string) $row['slug'],
            content: (string) $row['content'],
            category: (string) $row['category'],
            isPublished: ((int) $row['is_published']) === 1,
            publishedAt: $row['published_at'] !== null ? (string) $row['published_at'] : null,
            expiresAt: $row['expires_at'] !== null ? (string) $row['expires_at'] : null,
            isPinned: ((int) $row['is_pinned']) === 1,
            authorAdminId: $row['author_admin_id'] !== null ? (int) $row['author_admin_id'] : null,
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at']
        );
    }

    private function generateSlug(string $title): string
    {
        $map = [
            'ç' => 'c', 'Ç' => 'c',
            'ğ' => 'g', 'Ğ' => 'g',
            'ı' => 'i', 'I' => 'i', 'İ' => 'i',
            'ö' => 'o', 'Ö' => 'o',
            'ş' => 's', 'Ş' => 's',
            'ü' => 'u', 'Ü' => 'u',
        ];
        $transliterated = strtr($title, $map);
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $transliterated) ?? 'announcement', '-'));
        return $slug . '-' . bin2hex(random_bytes(3));
    }

    private function ensureSchema(): void
    {
        if ($this->pdo === null) {
            return;
        }

        $id = PdoSchema::autoIncrement($this->pdo);
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS announcements (
                id {$id},
                title VARCHAR(255) NOT NULL,
                slug VARCHAR(255) NOT NULL,
                content TEXT NOT NULL,
                category VARCHAR(64) NOT NULL DEFAULT 'general',
                is_published INTEGER NOT NULL DEFAULT 0,
                published_at VARCHAR(64),
                expires_at VARCHAR(64),
                is_pinned INTEGER NOT NULL DEFAULT 0,
                author_admin_id INTEGER,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )"
        );
    }
}
