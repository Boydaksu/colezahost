<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Adapters\Notification;

use Coleza\Domain\Automation\Actions\ActionHandlerInterface;
use Coleza\Domain\Automation\Actions\ActionInterface;
use Coleza\Domain\Automation\Actions\ActionResult;
use Coleza\Domain\Automation\Triggers\TriggerContext;
use Coleza\Domain\Notifications\Center\NotificationCenterService;
use Throwable;

final class InAppNotificationActionHandler implements ActionHandlerInterface
{
    public function __construct(
        private readonly NotificationCenterService $notificationCenter
    ) {
    }

    public function getType(): string
    {
        return 'notification.in_app';
    }

    public function execute(ActionInterface $action, TriggerContext $context): ActionResult
    {
        $start = microtime(true);
        $resolved = $action->resolveParameters($context);

        $rawUserId = $resolved['user_id'] ?? $context->get('user.id') ?? $context->get('user_id');
        $title = (string) ($resolved['title'] ?? '');
        $message = (string) ($resolved['message'] ?? '');

        if ($rawUserId === null || $title === '' || $message === '') {
            $durationMs = (microtime(true) - $start) * 1000;
            return ActionResult::failed(
                $action->getId(),
                $this->getType(),
                "Missing required parameters: 'user_id', 'title', or 'message'",
                [],
                $durationMs
            );
        }

        $userId = (int) $rawUserId;
        $actionUrl = isset($resolved['action_url']) ? (string) $resolved['action_url'] : null;
        $type = (string) ($resolved['type'] ?? 'info');
        $metadata = (array) ($resolved['metadata'] ?? []);

        try {
            $notification = $this->notificationCenter->createInAppNotification(
                userId: $userId,
                title: $title,
                message: $message,
                actionUrl: $actionUrl,
                type: $type,
                metadata: $metadata
            );

            $durationMs = (microtime(true) - $start) * 1000;

            return ActionResult::success($action->getId(), $this->getType(), [
                'notification_id' => $notification->getId(),
                'user_id' => $userId,
                'title' => $title,
                'type' => $type,
            ], $durationMs);
        } catch (Throwable $e) {
            $durationMs = (microtime(true) - $start) * 1000;
            return ActionResult::failed(
                $action->getId(),
                $this->getType(),
                'In-app notification creation failed: ' . $e->getMessage(),
                ['user_id' => $userId],
                $durationMs
            );
        }
    }
}
