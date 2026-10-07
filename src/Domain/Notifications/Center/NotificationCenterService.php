<?php

declare(strict_types=1);

namespace Coleza\Domain\Notifications\Center;

use Coleza\Domain\Notifications\Channel\NotificationChannel;
use Coleza\Domain\Notifications\History\NotificationLog;
use Coleza\Domain\Notifications\Preferences\NotificationPreference;
use InvalidArgumentException;
use PDO;

final class NotificationCenterService
{
    /**
     * @var array<string, NotificationPreference> In-memory cache when PDO is null
     */
    private array $memoryPreferences = [];

    /**
     * @var array<int, InAppNotification>
     */
    private array $memoryInApp = [];

    /**
     * @var array<int, NotificationLog>
     */
    private array $memoryLogs = [];

    public function __construct(
        private ?PDO $pdo = null
    ) {
        if ($this->pdo !== null) {
            $this->ensureSchema();
        }
    }

    /**
     * @return array<string, NotificationPreference>
     */
    public function getUserPreferences(int $userId): array
    {
        $categories = [
            NotificationPreference::CATEGORY_BILLING,
            NotificationPreference::CATEGORY_SERVICES,
            NotificationPreference::CATEGORY_SUPPORT,
            NotificationPreference::CATEGORY_MARKETING,
            NotificationPreference::CATEGORY_SECURITY,
        ];

        $preferences = [];

        if ($this->pdo === null) {
            foreach ($categories as $cat) {
                $key = "{$userId}:{$cat}";
                $preferences[$cat] = $this->memoryPreferences[$key] ?? new NotificationPreference(
                    userId: $userId,
                    category: $cat,
                    emailEnabled: true,
                    inAppEnabled: true,
                    smsEnabled: false
                );
            }
            return $preferences;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM notification_preferences WHERE user_id = :user_id');
        $stmt->execute([':user_id' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $saved = [];
        foreach ($rows as $row) {
            $cat = (string) $row['category'];
            $saved[$cat] = new NotificationPreference(
                userId: $userId,
                category: $cat,
                emailEnabled: ((int) $row['email_enabled']) === 1,
                inAppEnabled: ((int) $row['in_app_enabled']) === 1,
                smsEnabled: ((int) $row['sms_enabled']) === 1
            );
        }

        foreach ($categories as $cat) {
            $preferences[$cat] = $saved[$cat] ?? new NotificationPreference(
                userId: $userId,
                category: $cat,
                emailEnabled: true,
                inAppEnabled: true,
                smsEnabled: false
            );
        }

        return $preferences;
    }

    public function setPreference(
        int $userId,
        string $category,
        bool $email,
        bool $inApp,
        bool $sms
    ): NotificationPreference {
        if (in_array($category, NotificationPreference::MANDATORY_CATEGORIES, true)) {
            // Cannot disable mandatory security notifications
            if (!$email || !$inApp) {
                throw new InvalidArgumentException("Mandatory category '{$category}' cannot be disabled.");
            }
        }

        $pref = new NotificationPreference(
            userId: $userId,
            category: $category,
            emailEnabled: $email,
            inAppEnabled: $inApp,
            smsEnabled: $sms
        );

        if ($this->pdo === null) {
            $key = "{$userId}:{$category}";
            $this->memoryPreferences[$key] = $pref;
            return $pref;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO notification_preferences (user_id, category, email_enabled, in_app_enabled, sms_enabled)
             VALUES (:user_id, :category, :email, :in_app, :sms)
             ON CONFLICT(user_id, category) DO UPDATE SET
                email_enabled = :email,
                in_app_enabled = :in_app,
                sms_enabled = :sms'
        );

        $stmt->execute([
            ':user_id' => $userId,
            ':category' => $category,
            ':email' => $email ? 1 : 0,
            ':in_app' => $inApp ? 1 : 0,
            ':sms' => $sms ? 1 : 0,
        ]);

        return $pref;
    }

    public function isChannelEnabled(int $userId, string $category, NotificationChannel $channel): bool
    {
        // Mandatory categories are always enabled on email and in-app
        if (in_array($category, NotificationPreference::MANDATORY_CATEGORIES, true)) {
            return $channel === NotificationChannel::EMAIL || $channel === NotificationChannel::IN_APP;
        }

        $prefs = $this->getUserPreferences($userId);
        $pref = $prefs[$category] ?? null;

        if ($pref === null) {
            return true; // Default opt-in
        }

        return match ($channel) {
            NotificationChannel::EMAIL => $pref->isEmailEnabled(),
            NotificationChannel::IN_APP => $pref->isInAppEnabled(),
            NotificationChannel::SMS => $pref->isSmsEnabled(),
            default => true,
        };
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function createInAppNotification(
        int $userId,
        string $title,
        string $message,
        ?string $actionUrl = null,
        string $type = 'info',
        array $metadata = []
    ): InAppNotification {
        $now = date('c');

        if ($this->pdo === null) {
            $id = count($this->memoryInApp) + 1;
            $notif = new InAppNotification(
                id: $id,
                userId: $userId,
                title: $title,
                message: $message,
                actionUrl: $actionUrl,
                type: $type,
                isRead: false,
                readAt: null,
                createdAt: $now,
                metadata: $metadata
            );
            $this->memoryInApp[$id] = $notif;
            return $notif;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO in_app_notifications (user_id, title, message, action_url, type, is_read, created_at, metadata_json)
             VALUES (:user_id, :title, :message, :action_url, :type, 0, :created_at, :metadata)'
        );

        $stmt->execute([
            ':user_id' => $userId,
            ':title' => $title,
            ':message' => $message,
            ':action_url' => $actionUrl,
            ':type' => $type,
            ':created_at' => $now,
            ':metadata' => json_encode($metadata),
        ]);

        $id = (int) $this->pdo->lastInsertId();

        return new InAppNotification(
            id: $id,
            userId: $userId,
            title: $title,
            message: $message,
            actionUrl: $actionUrl,
            type: $type,
            isRead: false,
            readAt: null,
            createdAt: $now,
            metadata: $metadata
        );
    }

    /**
     * @return array<InAppNotification>
     */
    public function getUserNotifications(
        int $userId,
        bool $unreadOnly = false,
        int $limit = 20,
        int $offset = 0
    ): array {
        if ($this->pdo === null) {
            $matches = array_filter($this->memoryInApp, function (InAppNotification $n) use ($userId, $unreadOnly) {
                if ($n->getUserId() !== $userId) {
                    return false;
                }
                return !$unreadOnly || !$n->isRead();
            });
            $sorted = array_reverse(array_values($matches));
            return array_slice($sorted, $offset, $limit);
        }

        $sql = 'SELECT * FROM in_app_notifications WHERE user_id = :user_id';
        if ($unreadOnly) {
            $sql .= ' AND is_read = 0';
        }
        $sql .= ' ORDER BY id DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = new InAppNotification(
                id: (int) $row['id'],
                userId: (int) $row['user_id'],
                title: (string) $row['title'],
                message: (string) $row['message'],
                actionUrl: $row['action_url'] !== null ? (string) $row['action_url'] : null,
                type: (string) $row['type'],
                isRead: ((int) $row['is_read']) === 1,
                readAt: $row['read_at'] !== null ? (string) $row['read_at'] : null,
                createdAt: (string) $row['created_at'],
                metadata: !empty($row['metadata_json']) ? json_decode((string) $row['metadata_json'], true) : []
            );
        }

        return $results;
    }

    public function getUnreadCount(int $userId): int
    {
        if ($this->pdo === null) {
            return count(array_filter($this->memoryInApp, fn($n) => $n->getUserId() === $userId && !$n->isRead()));
        }

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM in_app_notifications WHERE user_id = :user_id AND is_read = 0');
        $stmt->execute([':user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    public function markAsRead(int $notificationId, int $userId): bool
    {
        $now = date('c');

        if ($this->pdo === null) {
            $n = $this->memoryInApp[$notificationId] ?? null;
            if ($n === null || $n->getUserId() !== $userId) {
                return false;
            }
            $this->memoryInApp[$notificationId] = new InAppNotification(
                id: $n->getId(),
                userId: $n->getUserId(),
                title: $n->getTitle(),
                message: $n->getMessage(),
                actionUrl: $n->getActionUrl(),
                type: $n->getType(),
                isRead: true,
                readAt: $now,
                createdAt: $n->getCreatedAt(),
                metadata: $n->getMetadata()
            );
            return true;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE in_app_notifications SET is_read = 1, read_at = :read_at WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute([
            ':read_at' => $now,
            ':id' => $notificationId,
            ':user_id' => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function markAllAsRead(int $userId): int
    {
        $now = date('c');

        if ($this->pdo === null) {
            $count = 0;
            foreach ($this->memoryInApp as $id => $n) {
                if ($n->getUserId() === $userId && !$n->isRead()) {
                    $this->memoryInApp[$id] = new InAppNotification(
                        id: $n->getId(),
                        userId: $n->getUserId(),
                        title: $n->getTitle(),
                        message: $n->getMessage(),
                        actionUrl: $n->getActionUrl(),
                        type: $n->getType(),
                        isRead: true,
                        readAt: $now,
                        createdAt: $n->getCreatedAt(),
                        metadata: $n->getMetadata()
                    );
                    $count++;
                }
            }
            return $count;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE in_app_notifications SET is_read = 1, read_at = :read_at WHERE user_id = :user_id AND is_read = 0'
        );
        $stmt->execute([
            ':read_at' => $now,
            ':user_id' => $userId,
        ]);

        return $stmt->rowCount();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function recordDelivery(
        NotificationChannel $channel,
        string $recipient,
        string $subject,
        string $status,
        ?int $userId = null,
        ?string $templateKey = null,
        ?string $messageId = null,
        ?string $error = null,
        array $metadata = []
    ): NotificationLog {
        $now = date('c');

        if ($this->pdo === null) {
            $id = count($this->memoryLogs) + 1;
            $log = new NotificationLog(
                id: $id,
                userId: $userId,
                channel: $channel,
                templateKey: $templateKey,
                subject: $subject,
                recipient: $recipient,
                status: $status,
                messageId: $messageId,
                error: $error,
                sentAt: $now,
                metadata: $metadata
            );
            $this->memoryLogs[$id] = $log;
            return $log;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO notification_logs (
                user_id, channel, template_key, subject, recipient, status, message_id, error, sent_at, metadata_json
            ) VALUES (
                :user_id, :channel, :template_key, :subject, :recipient, :status, :message_id, :error, :sent_at, :metadata
            )'
        );

        $stmt->execute([
            ':user_id' => $userId,
            ':channel' => $channel->value,
            ':template_key' => $templateKey,
            ':subject' => $subject,
            ':recipient' => $recipient,
            ':status' => $status,
            ':message_id' => $messageId,
            ':error' => $error,
            ':sent_at' => $now,
            ':metadata' => json_encode($metadata),
        ]);

        $id = (int) $this->pdo->lastInsertId();

        return new NotificationLog(
            id: $id,
            userId: $userId,
            channel: $channel,
            templateKey: $templateKey,
            subject: $subject,
            recipient: $recipient,
            status: $status,
            messageId: $messageId,
            error: $error,
            sentAt: $now,
            metadata: $metadata
        );
    }

    /**
     * @return array<NotificationLog>
     */
    public function getDeliveryLogs(?int $userId = null, int $limit = 50, int $offset = 0): array
    {
        if ($this->pdo === null) {
            $matches = array_filter($this->memoryLogs, function (NotificationLog $l) use ($userId) {
                return $userId === null || $l->getUserId() === $userId;
            });
            $sorted = array_reverse(array_values($matches));
            return array_slice($sorted, $offset, $limit);
        }

        $sql = 'SELECT * FROM notification_logs';
        if ($userId !== null) {
            $sql .= ' WHERE user_id = :user_id';
        }
        $sql .= ' ORDER BY id DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        if ($userId !== null) {
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = new NotificationLog(
                id: (int) $row['id'],
                userId: $row['user_id'] !== null ? (int) $row['user_id'] : null,
                channel: NotificationChannel::from((string) $row['channel']),
                templateKey: $row['template_key'] !== null ? (string) $row['template_key'] : null,
                subject: (string) $row['subject'],
                recipient: (string) $row['recipient'],
                status: (string) $row['status'],
                messageId: $row['message_id'] !== null ? (string) $row['message_id'] : null,
                error: $row['error'] !== null ? (string) $row['error'] : null,
                sentAt: (string) $row['sent_at'],
                metadata: !empty($row['metadata_json']) ? json_decode((string) $row['metadata_json'], true) : []
            );
        }

        return $results;
    }

    private function ensureSchema(): void
    {
        if ($this->pdo === null) {
            return;
        }

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS notification_preferences (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                category VARCHAR(64) NOT NULL,
                email_enabled INTEGER NOT NULL DEFAULT 1,
                in_app_enabled INTEGER NOT NULL DEFAULT 1,
                sms_enabled INTEGER NOT NULL DEFAULT 0,
                UNIQUE(user_id, category)
            );
            CREATE TABLE IF NOT EXISTS in_app_notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                title VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                action_url VARCHAR(255),
                type VARCHAR(32) NOT NULL DEFAULT "info",
                is_read INTEGER NOT NULL DEFAULT 0,
                read_at VARCHAR(64),
                created_at VARCHAR(64) NOT NULL,
                metadata_json TEXT
            );
            CREATE TABLE IF NOT EXISTS notification_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                channel VARCHAR(32) NOT NULL,
                template_key VARCHAR(64),
                subject VARCHAR(255) NOT NULL,
                recipient VARCHAR(255) NOT NULL,
                status VARCHAR(32) NOT NULL,
                message_id VARCHAR(128),
                error TEXT,
                sent_at VARCHAR(64) NOT NULL,
                metadata_json TEXT
            );'
        );
    }
}
