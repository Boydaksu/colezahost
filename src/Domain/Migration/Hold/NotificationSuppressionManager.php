<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Hold;

use Coleza\Domain\Notifications\Channel\NotificationChannel;
use Coleza\Domain\Notifications\Channel\NotificationPriority;
use Coleza\Domain\Notifications\Delivery\DeliveryResult;
use Coleza\Domain\Notifications\NotificationEngine;
use RuntimeException;

/**
 * Intercepts, records, and suppresses outbound customer notifications during migration runs
 * and active entity holds, preventing shock-wave communications.
 */
final class NotificationSuppressionManager
{
    public function __construct(
        private MigrationHoldService $holdService,
        private SuppressedNotificationRepository $repository,
        private ?NotificationEngine $engine = null,
        private bool $forceSuppression = false
    ) {
    }

    public function setForceSuppression(bool $force): void
    {
        $this->forceSuppression = $force;
    }

    public function isForceSuppression(): bool
    {
        return $this->forceSuppression;
    }

    /**
     * Determines whether an outbound notification should be suppressed.
     */
    public function isSuppressed(
        string $notificationType,
        ?string $entityType = null,
        int|string|null $entityId = null
    ): bool {
        if ($this->forceSuppression) {
            return true;
        }

        if ($this->holdService->isGlobalHoldActive()) {
            return true;
        }

        if ($entityType !== null && $entityId !== null) {
            return $this->holdService->isEntityHeld($entityType, $entityId);
        }

        return false;
    }

    /**
     * Records a suppressed notification to the audit repository.
     *
     * @param array<string, mixed> $payload
     */
    public function logSuppression(
        string $batchId,
        string $recipientEmail,
        string $notificationType,
        ?string $entityType = null,
        int|string|null $entityId = null,
        array $payload = [],
        string $reason = 'Suppressed due to migration hold'
    ): SuppressedNotification {
        $record = new SuppressedNotification(
            batchId: $batchId,
            recipientEmail: $recipientEmail,
            notificationType: $notificationType,
            reason: $reason,
            entityType: $entityType,
            entityId: $entityId,
            payload: $payload
        );

        return $this->repository->save($record);
    }

    /**
     * Either dispatches the notification via NotificationEngine, or intercepts and suppresses it.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     */
    public function dispatchOrSuppress(
        string $batchId,
        string $templateKey,
        array $data,
        string $recipientEmail,
        string $recipientLocale = 'en',
        ?string $recipientName = null,
        ?int $recipientUserId = null,
        ?string $entityType = null,
        int|string|null $entityId = null,
        NotificationChannel $channel = NotificationChannel::EMAIL,
        NotificationPriority $priority = NotificationPriority::NORMAL,
        array $options = []
    ): DeliveryResult {
        if ($this->isSuppressed($templateKey, $entityType, $entityId)) {
            $suppressed = $this->logSuppression(
                batchId: $batchId,
                recipientEmail: $recipientEmail,
                notificationType: $templateKey,
                entityType: $entityType,
                entityId: $entityId,
                payload: [
                    'template' => $templateKey,
                    'data' => $data,
                    'locale' => $recipientLocale,
                    'recipient_name' => $recipientName,
                    'recipient_user_id' => $recipientUserId,
                    'channel' => $channel->value,
                    'priority' => $priority->value,
                    'options' => $options,
                ],
                reason: 'Migration hold active: notification intercepted and suppressed'
            );

            return DeliveryResult::failure(
                error: 'Suppressed due to migration hold',
                transport: 'suppression_intercept',
                messageId: null,
                metadata: [
                    'suppressed' => true,
                    'suppressed_id' => $suppressed->getId(),
                    'batch_id' => $batchId,
                ]
            );
        }

        if ($this->engine === null) {
            throw new RuntimeException('NotificationEngine is required to dispatch unsuppressed notifications.');
        }

        return $this->engine->sendNotification(
            templateKey: $templateKey,
            data: $data,
            recipientEmail: $recipientEmail,
            recipientLocale: $recipientLocale,
            recipientName: $recipientName,
            recipientUserId: $recipientUserId,
            channel: $channel,
            priority: $priority,
            options: $options
        );
    }

    /**
     * Safely replays an administrative-approved suppressed notification.
     */
    public function replaySuppressed(int $suppressedId, ?NotificationEngine $overrideEngine = null): DeliveryResult
    {
        $item = $this->repository->find($suppressedId);
        if ($item === null) {
            throw new RuntimeException("Suppressed notification [{$suppressedId}] not found.");
        }

        if ($item->isReplayed()) {
            throw new RuntimeException("Suppressed notification [{$suppressedId}] has already been replayed.");
        }

        $engine = $overrideEngine ?? $this->engine;
        if ($engine === null) {
            throw new RuntimeException('NotificationEngine is required to replay suppressed notifications.');
        }

        $payload = $item->getPayload();
        $template = (string) ($payload['template'] ?? $item->getNotificationType());
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $locale = (string) ($payload['locale'] ?? 'en');
        $name = isset($payload['recipient_name']) ? (string) $payload['recipient_name'] : null;
        $userId = isset($payload['recipient_user_id']) ? (int) $payload['recipient_user_id'] : null;
        $channel = isset($payload['channel']) ? NotificationChannel::from((string) $payload['channel']) : NotificationChannel::EMAIL;
        $priority = isset($payload['priority']) ? NotificationPriority::from((string) $payload['priority']) : NotificationPriority::NORMAL;
        $options = is_array($payload['options'] ?? null) ? $payload['options'] : [];

        $result = $engine->sendNotification(
            templateKey: $template,
            data: $data,
            recipientEmail: $item->getRecipientEmail(),
            recipientLocale: $locale,
            recipientName: $name,
            recipientUserId: $userId,
            channel: $channel,
            priority: $priority,
            options: $options
        );

        if ($result->isSuccess()) {
            $this->repository->markReplayed($suppressedId);
        }

        return $result;
    }

    /**
     * @return list<SuppressedNotification>
     */
    public function getSuppressedNotifications(string $batchId, ?string $status = null): array
    {
        return $this->repository->findByBatch($batchId, $status);
    }

    /**
     * @return array{total_suppressed: int, replayed: int, pending: int, by_type: array<string, int>}
     */
    public function getSummary(string $batchId): array
    {
        return $this->repository->getSummary($batchId);
    }

    public function getHoldService(): MigrationHoldService
    {
        return $this->holdService;
    }

    public function getRepository(): SuppressedNotificationRepository
    {
        return $this->repository;
    }
}
